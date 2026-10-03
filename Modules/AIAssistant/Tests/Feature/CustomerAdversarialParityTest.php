<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Payment;
use App\Models\Returns;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Read\CustomerReadService;
use App\Services\ReceivableReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Skills\CustomerDueSkill;
use Tests\TestCase;

class CustomerAdversarialParityTest extends TestCase
{
    use DatabaseTransactions;

    private ReceivableReconciliationService $reconciliationService;
    private CustomerReadService $readService;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private CustomerGroup $customerGroup;
    private Account $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");

        $this->reconciliationService = app(ReceivableReconciliationService::class);
        $this->readService = app(CustomerReadService::class);

        $this->warehouseA = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 1],
            ['name' => 'Cust Adv WH A', 'address' => 'Addr A', 'is_active' => true]
        );
        $this->warehouseB = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 2],
            ['name' => 'Cust Adv WH B', 'address' => 'Addr B', 'is_active' => true]
        );
        $this->customerGroup = CustomerGroup::firstOrCreate(
            ['name' => 'General Adv'],
            ['percentage' => '0', 'is_active' => true]
        );
        $this->cashAccount = Account::firstOrCreate(
            ['account_no' => 'ADV-CUST-CASH'],
            ['name' => 'Cust Cash', 'type' => 'Cash', 'initial_balance' => 50000, 'total_balance' => 50000, 'is_active' => true]
        );

        DB::table('general_settings')->updateOrInsert(['id' => 1], [
            'site_title' => 'SalePro',
            'site_logo' => 'logo.png',
            'currency' => 1,
            'staff_access' => 'all'
        ]);
        DB::table('general_settings')->update(['staff_access' => 'all']);
        Cache::forget('general_setting');

        DB::table('roles')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Admin', 'guard_name' => 'web', 'is_active' => true]
        );
        DB::table('roles')->updateOrInsert(
            ['id' => 3],
            ['name' => 'Staff', 'guard_name' => 'web', 'is_active' => true]
        );

        $permId = DB::table('permissions')->where('name', 'customers-index')->value('id');
        if (!$permId) {
            $permId = DB::table('permissions')->insertGetId(['name' => 'customers-index', 'guard_name' => 'web']);
        }
        DB::table('role_has_permissions')->updateOrInsert(['role_id' => 3, 'permission_id' => $permId]);
    }

    public function test_adversarial_customer_receivable_lifecycle_parity_and_anti_spoofing(): void
    {
        $adminUser = User::create([
            'name' => 'Cust Admin',
            'email' => 'cust_admin_' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role_id' => 1,
            'warehouse_id' => $this->warehouseA->id,
            'is_active' => true,
        ]);
        $this->actingAs($adminUser);

        // 1. Customer with Opening Balance (200.00)
        $customer = Customer::create([
            'name' => 'Adversarial Customer A',
            'customer_group_id' => $this->customerGroup->id,
            'company_name' => 'Adv Customer Co',
            'email' => 'adv_cust_' . uniqid() . '@example.test',
            'phone_number' => '555-0299',
            'address' => '200 Customer Plaza',
            'city' => 'Metropolis',
            'opening_balance' => 200.0,
            'is_active' => true,
        ]);

        // 2. Warehouse A: Completed Sale 1 (500.00) with Partial Payment (150.00) -> due 350.00
        $saleA1 = Sale::create([
            'reference_no' => 'SALE-ADV-A1-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 5,
            'total_price' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 150.0,
            'sale_status' => 1,
            'payment_status' => 2,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        Payment::create([
            'payment_reference' => 'PAY-CUST-A1-' . uniqid(),
            'sale_id' => $saleA1->id,
            'user_id' => $adminUser->id,
            'account_id' => $this->cashAccount->id,
            'amount' => 150.0,
            'paying_method' => 'Cash',
            'payment_at' => Carbon::now(),
        ]);

        // 3. Warehouse A: Second Sale (600.00) with payment (200.00), Partial Linked Return (100.00) and Refund (30.00)
        // Net due on Sale A2 = 600 - 200 - 100 + 30 = 330.00
        $saleA2 = Sale::create([
            'reference_no' => 'SALE-ADV-A2-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 6,
            'total_price' => 600.0,
            'grand_total' => 600.0,
            'paid_amount' => 200.0,
            'sale_status' => 1,
            'payment_status' => 2,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        Payment::create([
            'payment_reference' => 'PAY-CUST-A2-' . uniqid(),
            'sale_id' => $saleA2->id,
            'user_id' => $adminUser->id,
            'account_id' => $this->cashAccount->id,
            'amount' => 200.0,
            'paying_method' => 'Cash',
            'payment_at' => Carbon::now(),
        ]);
        $returnA2 = Returns::create([
            'reference_no' => 'RET-CUST-A2-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'sale_id' => $saleA2->id,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 100.0,
            'grand_total' => 100.0,
        ]);
        Payment::create([
            'payment_reference' => 'REF-CUST-A2-' . uniqid(),
            'sale_id' => $saleA2->id,
            'return_id' => $returnA2->id,
            'user_id' => $adminUser->id,
            'account_id' => $this->cashAccount->id,
            'amount' => 30.0,
            'paying_method' => 'Cash',
            'payment_at' => Carbon::now(),
        ]);

        // 4. Warehouse A: Third Sale with Full Return (120.00 sale, 120.00 return -> 0 due)
        $saleA3 = Sale::create([
            'reference_no' => 'SALE-ADV-A3-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 120.0,
            'grand_total' => 120.0,
            'paid_amount' => 0.0,
            'sale_status' => 1,
            'payment_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        Returns::create([
            'reference_no' => 'RET-CUST-A3-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'sale_id' => $saleA3->id,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 120.0,
            'grand_total' => 120.0,
        ]);

        // 5. Inactive / Draft / Voided / Soft-deleted / Reversed transactions in Warehouse A (MUST be ignored)
        Sale::create([
            'reference_no' => 'SALE-DRAFT-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 800.0,
            'grand_total' => 800.0,
            'paid_amount' => 0.0,
            'sale_status' => ReceivableReconciliationService::DRAFT_STATUS, // Draft
            'payment_status' => 1,
        ]);
        Sale::create([
            'reference_no' => 'SALE-VOIDED-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 900.0,
            'grand_total' => 900.0,
            'paid_amount' => 0.0,
            'sale_status' => 1,
            'payment_status' => 1,
            'voided_at' => Carbon::now(),
        ]);
        $deletedSale = Sale::create([
            'reference_no' => 'SALE-DELETED-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 1000.0,
            'grand_total' => 1000.0,
            'paid_amount' => 0.0,
            'sale_status' => 1,
            'payment_status' => 1,
        ]);
        $deletedSale->delete();
        Sale::create([
            'reference_no' => 'SALE-REVERSED-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 1100.0,
            'grand_total' => 1100.0,
            'paid_amount' => 0.0,
            'sale_status' => 1,
            'payment_status' => 1,
            'accounting_status' => 'reversed',
        ]);

        // 6. Warehouse B: Separate Sale (400.00) with Partial Payment (100.00) -> due 300.00
        $saleB = Sale::create([
            'reference_no' => 'SALE-ADV-B-' . uniqid(),
            'user_id' => $adminUser->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouseB->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 4,
            'total_price' => 400.0,
            'grand_total' => 400.0,
            'paid_amount' => 100.0,
            'sale_status' => 1,
            'payment_status' => 2,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        Payment::create([
            'payment_reference' => 'PAY-CUST-B-' . uniqid(),
            'sale_id' => $saleB->id,
            'user_id' => $adminUser->id,
            'account_id' => $this->cashAccount->id,
            'amount' => 100.0,
            'paying_method' => 'Cash',
            'payment_at' => Carbon::now(),
        ]);

        // CALCULATE AUTHORITATIVE RECONCILIATION TOTALS:
        // Warehouse A sales due = (500 - 150) + (600 - 200 - 100 + 30) + (120 - 120) = 350 + 330 + 0 = 680.00
        // Warehouse B sales due = (400 - 100) = 300.00
        // Customer opening balance = 200.00
        // Global Authoritative Due = 680.00 + 300.00 + 200.00 = 1180.00
        // Warehouse A Authoritative Due = 680.00
        // Warehouse B Authoritative Due = 300.00

        $authoritativeGlobal = $this->reconciliationService->operationalBalance($customer->id);
        $authoritativeWhA = $this->reconciliationService->operationalBalance($customer->id, null, [$this->warehouseA->id]);
        $authoritativeWhB = $this->reconciliationService->operationalBalance($customer->id, null, [$this->warehouseB->id]);

        $this->assertEquals(1180.0, $authoritativeGlobal, 'Authoritative global operational balance mismatch');
        $this->assertEquals(680.0, $authoritativeWhA, 'Authoritative WH A operational balance mismatch');
        $this->assertEquals(300.0, $authoritativeWhB, 'Authoritative WH B operational balance mismatch');

        // ==========================================
        // TEST 1: GLOBAL USER PARITY
        // ==========================================
        $globalContext = AssistantAccessContext::fromUser($adminUser);
        $readGlobal = $this->readService->balance($globalContext, $customer->id);
        $this->assertEquals($authoritativeGlobal, $readGlobal, 'CustomerReadService global balance must match authoritative reconciliation exactly');

        $skill = app(CustomerDueSkill::class);
        $msg = new AssistantMessageData('user', 'customer due summary');
        $assistantContextGlobal = new AssistantContextData(
            userId: $adminUser->id,
            accessContext: $globalContext
        );
        $responseGlobal = $skill->handle($msg, $assistantContextGlobal);

        $rowGlobal = collect($responseGlobal->table['rows'])->firstWhere(0, $customer->name);
        $this->assertNotNull($rowGlobal, 'Customer row missing in global response');
        $this->assertEquals($authoritativeGlobal, $rowGlobal[1], 'Assistant table customer due must match authoritative reconciliation');
        $this->assertFalse($responseGlobal->metadata['failed_closed'] ?? false);

        // ==========================================
        // TEST 2: RESTRICTED WAREHOUSE A USER PARITY
        // ==========================================
        $staffWhA = User::create([
            'name' => 'Cust Staff WH A',
            'email' => 'cust_staff_a_' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role_id' => 3,
            'warehouse_id' => $this->warehouseA->id,
            'is_active' => true,
        ]);
        $staffAContext = AssistantAccessContext::fromUser($staffWhA);
        $this->assertTrue($staffAContext->isRestrictedWarehouseAccess);
        $this->assertEquals([$this->warehouseA->id], $staffAContext->allowedWarehouseIds);

        $readWhA = $this->readService->balance($staffAContext, $customer->id);
        $this->assertEquals($authoritativeWhA, $readWhA, 'CustomerReadService WH A balance must match authoritative WH A');

        $assistantContextWhA = new AssistantContextData(
            userId: $staffWhA->id,
            accessContext: $staffAContext
        );
        $responseWhA = $skill->handle($msg, $assistantContextWhA);

        $rowWhA = collect($responseWhA->table['rows'])->firstWhere(0, $customer->name);
        $this->assertNotNull($rowWhA, 'Customer row missing in WH A response');
        $this->assertEquals($authoritativeWhA, $rowWhA[1], 'Assistant table customer due must match authoritative WH A');
        $this->assertEquals([$this->warehouseA->id], $responseWhA->metadata['warehouse_ids']);

        // ==========================================
        // TEST 3: ANTI-SPOOFING (Restricted user tries to request WH B)
        // ==========================================
        $spoofedContext = AssistantAccessContext::fromUser($staffWhA, ['warehouse_id' => $this->warehouseB->id]);
        $this->assertEquals([$this->warehouseA->id], $spoofedContext->allowedWarehouseIds);

        $assistantContextSpoofed = new AssistantContextData(
            userId: $staffWhA->id,
            businessContext: ['warehouse_ids' => [$this->warehouseB->id]], // client forged
            accessContext: $spoofedContext
        );
        $responseSpoofed = $skill->handle($msg, $assistantContextSpoofed);

        $rowSpoofed = collect($responseSpoofed->table['rows'])->firstWhere(0, $customer->name);
        $this->assertNotNull($rowSpoofed);
        $this->assertEquals($authoritativeWhA, $rowSpoofed[1], 'Spoofed context must not leak WH B due');
        $this->assertEquals([$this->warehouseA->id], $responseSpoofed->metadata['warehouse_ids']);
    }
}
