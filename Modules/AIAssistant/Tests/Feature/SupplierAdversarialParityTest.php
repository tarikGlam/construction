<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\Account;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\ReturnPurchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Read\SupplierReadService;
use App\Services\SupplierDuePaymentService;
use App\Services\SupplierOpeningBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\AssistantOrchestrator;
use Modules\AIAssistant\Skills\SupplierDueSkill;
use Tests\TestCase;

class SupplierAdversarialParityTest extends TestCase
{
    use DatabaseTransactions;

    private SupplierDuePaymentService $duePaymentService;
    private SupplierReadService $readService;
    private SupplierOpeningBalanceService $openingBalanceService;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private Account $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");

        $this->duePaymentService = app(SupplierDuePaymentService::class);
        $this->readService = app(SupplierReadService::class);
        $this->openingBalanceService = app(SupplierOpeningBalanceService::class);

        $this->warehouseA = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 1],
            ['name' => 'Adversarial WH A', 'address' => 'Addr A', 'is_active' => true]
        );
        $this->warehouseB = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 2],
            ['name' => 'Adversarial WH B', 'address' => 'Addr B', 'is_active' => true]
        );

        $this->cashAccount = Account::firstOrCreate(
            ['account_no' => 'ADV-CASH-001'],
            ['name' => 'Adversarial Cash', 'type' => 'Cash', 'initial_balance' => 100000, 'total_balance' => 100000, 'is_active' => true]
        );

        DB::table('general_settings')->updateOrInsert(['id' => 1], [
            'site_title' => 'SalePro',
            'site_logo' => 'logo.png',
            'currency' => 1,
            'staff_access' => 'all'
        ]);
        DB::table('general_settings')->update(['staff_access' => 'all']);
        \Illuminate\Support\Facades\Cache::forget('general_setting');

        DB::table('roles')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Admin', 'guard_name' => 'web', 'is_active' => true]
        );
        DB::table('roles')->updateOrInsert(
            ['id' => 3],
            ['name' => 'Staff', 'guard_name' => 'web', 'is_active' => true]
        );

        // Ensure permission exists for staff
        $permId = DB::table('permissions')->where('name', 'suppliers-index')->value('id');
        if (!$permId) {
            $permId = DB::table('permissions')->insertGetId(['name' => 'suppliers-index', 'guard_name' => 'web']);
        }
        DB::table('role_has_permissions')->updateOrInsert(['role_id' => 3, 'permission_id' => $permId]);
    }

    public function test_adversarial_supplier_due_lifecycle_parity_and_anti_spoofing(): void
    {
        // 1. Create Supplier A
        $supplier = Supplier::create([
            'name' => 'Adversarial Supplier A',
            'company_name' => 'Adv Supplier Co',
            'email' => 'adv_sup_' . uniqid() . '@example.test',
            'phone_number' => '555-0199',
            'address' => '100 Commercial Way',
            'city' => 'Metropolis',
            'is_active' => true,
        ]);

        $adminUser = User::create([
            'name' => 'Adv Admin',
            'email' => 'adv_admin_' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role_id' => 1,
            'warehouse_id' => $this->warehouseA->id,
            'is_active' => true,
        ]);
        $this->actingAs($adminUser);

        // 2. Warehouse A: Synthetic Opening Purchase (100.00)
        $this->openingBalanceService->change($supplier, 100.0);
        $openingPurchase = Purchase::where('supplier_id', $supplier->id)
            ->whereRaw('LOWER(purchase_type) = ?', ['opening balance'])
            ->sole();
        $this->assertEquals(100.0, (float) $openingPurchase->grand_total);
        $this->assertEquals($this->warehouseA->id, $openingPurchase->warehouse_id);

        // 3. Warehouse A: Normal Purchase 1 (200.00)
        $purchaseA1 = Purchase::create([
            'reference_no' => 'PUR-ADV-A1-' . uniqid(),
            'user_id' => $adminUser->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouseA->id,
            'item' => 1,
            'total_qty' => 10,
            'total_cost' => 200.0,
            'grand_total' => 200.0,
            'paid_amount' => 0.0,
            'status' => 1,
            'payment_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // 4. Warehouse A: Partial Payment against Purchase 1 (50.00) -> remaining 150.00
        Payment::create([
            'payment_reference' => 'PAY-ADV-A1-' . uniqid(),
            'purchase_id' => $purchaseA1->id,
            'user_id' => $adminUser->id,
            'account_id' => $this->cashAccount->id,
            'amount' => 50.0,
            'paying_method' => 'Cash',
            'payment_at' => Carbon::now(),
        ]);
        $purchaseA1->paid_amount = 50.0;
        $purchaseA1->payment_status = 1;
        $purchaseA1->save();

        // 5. Warehouse A: Purchase 2 (300.00)
        $purchaseA2 = Purchase::create([
            'reference_no' => 'PUR-ADV-A2-' . uniqid(),
            'user_id' => $adminUser->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouseA->id,
            'item' => 1,
            'total_qty' => 15,
            'total_cost' => 300.0,
            'grand_total' => 300.0,
            'paid_amount' => 0.0,
            'status' => 1,
            'payment_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // 6. Warehouse A: Partial Return against Purchase 2 (60.00 return, with 20.00 refund)
        // Net return reducing due = 60 - 20 = 40.00. Remaining due on Purchase 2 = 300 - 40 = 260.00.
        $returnA2 = ReturnPurchase::create([
            'reference_no' => 'RET-ADV-A2-' . uniqid(),
            'user_id' => $adminUser->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouseA->id,
            'purchase_id' => $purchaseA2->id,
            'item' => 1,
            'total_qty' => 3,
            'total_cost' => 60.0,
            'grand_total' => 60.0,
        ]);
        Payment::create([
            'payment_reference' => 'REF-ADV-A2-' . uniqid(),
            'purchase_id' => $purchaseA2->id,
            'purchase_return_id' => $returnA2->id,
            'user_id' => $adminUser->id,
            'account_id' => $this->cashAccount->id,
            'amount' => 20.0,
            'paying_method' => 'Cash',
            'payment_at' => Carbon::now(),
        ]);

        // 7. Warehouse A: Supplier Due Payment via authoritative clear (80.00)
        // Clears 80 from available due in Warehouse A
        $this->duePaymentService->clear(
            $supplier,
            80.0,
            $this->cashAccount->id,
            'Cash'
        );

        // 8. Inactive/Voided/Cancelled purchases must be ignored
        Purchase::create([
            'reference_no' => 'PUR-CANCELLED-' . uniqid(),
            'user_id' => $adminUser->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouseA->id,
            'item' => 1,
            'total_qty' => 1,
            'total_cost' => 500.0,
            'grand_total' => 500.0,
            'paid_amount' => 0.0,
            'status' => 3, // Cancelled
            'payment_status' => 1,
        ]);

        // 9. Warehouse B: Separate Purchase (150.00) and Separate Payment (50.00) -> due 100.00
        $purchaseB = Purchase::create([
            'reference_no' => 'PUR-ADV-B-' . uniqid(),
            'user_id' => $adminUser->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouseB->id,
            'item' => 1,
            'total_qty' => 5,
            'total_cost' => 150.0,
            'grand_total' => 150.0,
            'paid_amount' => 50.0,
            'status' => 1,
            'payment_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        Payment::create([
            'payment_reference' => 'PAY-ADV-B-' . uniqid(),
            'purchase_id' => $purchaseB->id,
            'user_id' => $adminUser->id,
            'account_id' => $this->cashAccount->id,
            'amount' => 50.0,
            'paying_method' => 'Cash',
            'payment_at' => Carbon::now(),
        ]);

        // CALCULATE AUTHORITATIVE TOTALS:
        // Warehouse A:
        //   Opening: 100.00
        //   Purchase 1: 200 - 50 = 150.00
        //   Purchase 2: 300 - (60 - 20) = 260.00
        //   Subtotal A before clear: 100 + 150 + 260 = 510.00
        //   After 80.00 clear: 510.00 - 80.00 = 430.00
        // Warehouse B:
        //   Purchase B: 150 - 50 = 100.00
        // Global Total: 430.00 + 100.00 = 530.00

        $expectedDueWhA = 430.0;
        $expectedDueWhB = 100.0;
        $expectedDueGlobal = 530.0;

        // Authoritative verification
        $authoritativeGlobal = $this->duePaymentService->dueForSupplier($supplier->id);
        $authoritativeWhA = $this->duePaymentService->dueForSupplier($supplier->id, [$this->warehouseA->id]);
        $authoritativeWhB = $this->duePaymentService->dueForSupplier($supplier->id, [$this->warehouseB->id]);

        $this->assertEquals($expectedDueGlobal, $authoritativeGlobal, 'Authoritative global due mismatch');
        $this->assertEquals($expectedDueWhA, $authoritativeWhA, 'Authoritative WH A due mismatch');
        $this->assertEquals($expectedDueWhB, $authoritativeWhB, 'Authoritative WH B due mismatch');

        // ==========================================
        // TEST 1: GLOBAL USER PARITY
        // ==========================================
        $globalContext = AssistantAccessContext::fromUser($adminUser);
        $readGlobal = $this->readService->balance($globalContext, $supplier->id);
        $this->assertEquals($authoritativeGlobal, $readGlobal, 'SupplierReadService global balance must equal authoritative');

        $skill = app(SupplierDueSkill::class);
        $msg = new AssistantMessageData('user', 'supplier due summary');
        $assistantContextGlobal = new AssistantContextData(
            userId: $adminUser->id,
            accessContext: $globalContext
        );
        $responseGlobal = $skill->handle($msg, $assistantContextGlobal);

        $rowGlobal = collect($responseGlobal->table['rows'])->firstWhere(0, $supplier->name);
        $this->assertNotNull($rowGlobal, 'Supplier A row missing in global response');
        $this->assertEquals($authoritativeGlobal, $rowGlobal[1], 'Assistant table due must match authoritative global');
        $this->assertFalse($responseGlobal->metadata['failed_closed']);

        // ==========================================
        // TEST 2: RESTRICTED WAREHOUSE A USER PARITY
        // ==========================================
        $staffWhA = User::create([
            'name' => 'Staff WH A',
            'email' => 'staff_wh_a_' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role_id' => 3,
            'warehouse_id' => $this->warehouseA->id,
            'is_active' => true,
        ]);
        $staffAContext = AssistantAccessContext::fromUser($staffWhA);
        $this->assertTrue($staffAContext->isRestrictedWarehouseAccess);
        $this->assertEquals([$this->warehouseA->id], $staffAContext->allowedWarehouseIds);

        $readWhA = $this->readService->balance($staffAContext, $supplier->id);
        $this->assertEquals($authoritativeWhA, $readWhA, 'SupplierReadService WH A balance must equal authoritative WH A');

        $assistantContextWhA = new AssistantContextData(
            userId: $staffWhA->id,
            accessContext: $staffAContext
        );
        $responseWhA = $skill->handle($msg, $assistantContextWhA);

        $rowWhA = collect($responseWhA->table['rows'])->firstWhere(0, $supplier->name);
        $this->assertNotNull($rowWhA, 'Supplier A row missing in WH A response');
        $this->assertEquals($authoritativeWhA, $rowWhA[1], 'Assistant table due must match authoritative WH A');
        $this->assertEquals([$this->warehouseA->id], $responseWhA->metadata['warehouse_ids']);

        // ==========================================
        // TEST 3: ANTI-SPOOFING (Restricted user tries to request WH B)
        // ==========================================
        // User sends context with warehouse_id = WH B (2)
        $spoofedContext = AssistantAccessContext::fromUser($staffWhA, ['warehouse_id' => $this->warehouseB->id]);
        // AssistantAccessContext must refuse the spoof and confine to allowed WH A
        $this->assertEquals([$this->warehouseA->id], $spoofedContext->allowedWarehouseIds);

        $assistantContextSpoofed = new AssistantContextData(
            userId: $staffWhA->id,
            businessContext: ['warehouse_ids' => [$this->warehouseB->id]], // forged payload
            accessContext: $spoofedContext
        );
        $responseSpoofed = $skill->handle($msg, $assistantContextSpoofed);

        $rowSpoofed = collect($responseSpoofed->table['rows'])->firstWhere(0, $supplier->name);
        $this->assertNotNull($rowSpoofed);
        $this->assertEquals($authoritativeWhA, $rowSpoofed[1], 'Spoofed context must not leak WH B due');
        $this->assertEquals([$this->warehouseA->id], $responseSpoofed->metadata['warehouse_ids']);
    }
}
