<?php

namespace Modules\AIAssistant\Tests\Unit;

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
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Tests\TestCase;

class CustomerReceivableParityTest extends TestCase
{
    use DatabaseTransactions;

    private CustomerReadService $customerReadService;
    private ReceivableReconciliationService $reconciliationService;
    private Warehouse $warehouse1;
    private Warehouse $warehouse2;
    private CustomerGroup $customerGroup;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement("SET SESSION sql_mode=''");

        $this->reconciliationService = app(ReceivableReconciliationService::class);
        $this->customerReadService = app(CustomerReadService::class);

        $this->warehouse1 = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 1],
            ['name' => 'Warehouse 1', 'address' => 'Address 1', 'is_active' => true]
        );
        $this->warehouse2 = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 2],
            ['name' => 'Warehouse 2', 'address' => 'Address 2', 'is_active' => true]
        );
        $this->customerGroup = CustomerGroup::firstOrCreate(
            ['name' => 'General'],
            ['percentage' => '0', 'is_active' => true]
        );
    }

    private function createCustomer(string $name, float $openingBalance = 0.0): Customer
    {
        return Customer::create([
            'name' => $name,
            'customer_group_id' => $this->customerGroup->id,
            'company_name' => 'Co ' . $name,
            'email' => strtolower($name) . '_' . uniqid() . '@example.test',
            'phone_number' => '12345678',
            'address' => 'Test Address',
            'city' => 'City',
            'opening_balance' => $openingBalance,
            'is_active' => true,
        ]);
    }

    public function test_customer_balance_matches_authoritative_operational_balance(): void
    {
        $customer = $this->createCustomer('ParityCustA', openingBalance: 150.0);

        // Sale: 500 total, 200 paid -> 300 due
        Sale::create([
            'reference_no' => 'S-PAR-1',
            'user_id' => 1,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse1->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 500,
            'grand_total' => 500,
            'paid_amount' => 200,
            'payment_status' => 2,
            'sale_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        $authoritativeBalance = $this->reconciliationService->operationalBalance($customer->id);
        $readServiceBalance = $this->customerReadService->balance($context, $customer->id);

        $this->assertEquals($authoritativeBalance, $readServiceBalance, 'CustomerReadService::balance must match authoritative operationalBalance exactly');
        $this->assertEquals(450.0, $readServiceBalance);
    }

    public function test_customer_due_list_total_and_row_dues_match_authoritative_balances(): void
    {
        $c1 = $this->createCustomer('ParityCust1', openingBalance: 100.0);
        $c2 = $this->createCustomer('ParityCust2', openingBalance: 50.0);
        $c3 = $this->createCustomer('ParityCustZero', openingBalance: 0.0);

        // Sale for c2
        Sale::create([
            'reference_no' => 'S-PAR-2',
            'user_id' => 1,
            'customer_id' => $c2->id,
            'warehouse_id' => $this->warehouse1->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 200,
            'grand_total' => 200,
            'paid_amount' => 0,
            'payment_status' => 1,
            'sale_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        $dueList = $this->customerReadService->dueList($context, 10);
        $expectedC1 = $this->reconciliationService->operationalBalance($c1->id);
        $expectedC2 = $this->reconciliationService->operationalBalance($c2->id);

        $c1Row = collect($dueList['rows'])->firstWhere('customer_id', $c1->id);
        $c2Row = collect($dueList['rows'])->firstWhere('customer_id', $c2->id);
        $c3Row = collect($dueList['rows'])->firstWhere('customer_id', $c3->id);

        $this->assertNotNull($c1Row);
        $this->assertEquals($expectedC1, $c1Row['due']);
        $this->assertNotNull($c2Row);
        $this->assertEquals($expectedC2, $c2Row['due']);
        $this->assertNull($c3Row, 'Customer with zero balance should not appear in due list');
    }

    public function test_warehouse_restricted_due_list_matches_authoritative_scoped_balances(): void
    {
        $c = $this->createCustomer('WarehouseScopedCust', openingBalance: 1000.0);

        // Sale in warehouse 1: 300 due
        Sale::create([
            'reference_no' => 'S-WH1',
            'user_id' => 1,
            'customer_id' => $c->id,
            'warehouse_id' => $this->warehouse1->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 300,
            'grand_total' => 300,
            'paid_amount' => 0,
            'payment_status' => 1,
            'sale_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Sale in warehouse 2: 700 due
        Sale::create([
            'reference_no' => 'S-WH2',
            'user_id' => 1,
            'customer_id' => $c->id,
            'warehouse_id' => $this->warehouse2->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 700,
            'grand_total' => 700,
            'paid_amount' => 0,
            'payment_status' => 1,
            'sale_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Scope restricted to Warehouse 1 only
        $scopedContext = new WarehouseScope(
            isRestricted: true,
            warehouseIds: [$this->warehouse1->id],
            ownUserId: null
        );

        $authoritativeWh1 = $this->reconciliationService->operationalBalance($c->id, null, [$this->warehouse1->id]);
        $readServiceWh1 = $this->customerReadService->balance($scopedContext, $c->id);

        $this->assertEquals($authoritativeWh1, $readServiceWh1);
        $this->assertEquals(300.0, $readServiceWh1, 'Warehouse-restricted balance must reflect only warehouse 1 transactions');

        $dueList = $this->customerReadService->dueList($scopedContext, 10);
        $row = collect($dueList['rows'])->firstWhere('customer_id', $c->id);
        $this->assertNotNull($row);
        $this->assertEquals(300.0, $row['due']);
    }

    public function test_balance_does_not_fail_on_customer_beyond_due_list_limit(): void
    {
        $c = $this->createCustomer('BeyondLimitCust', openingBalance: 42.50);

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        // balance() directly evaluates customer without depending on limit
        $balance = $this->customerReadService->balance($context, $c->id);
        $this->assertEquals(42.50, $balance);
    }
}
