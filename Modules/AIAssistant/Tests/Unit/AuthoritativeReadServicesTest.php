<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Read\BusinessSummaryReadService;
use App\Services\Read\CustomerReadService;
use App\Services\Read\InventoryReadService;
use App\Services\Read\PurchaseReadService;
use App\Services\Read\SalesReadService;
use App\Services\Read\SupplierReadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\AIAssistant\Security\AssistantAccessContext;

class AuthoritativeReadServicesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement("SET SESSION sql_mode=''");
    }

    private function ensureWarehouse(int $id, string $name): Warehouse
    {
        return Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => $id],
            ['name' => $name, 'address' => 'Test Address', 'is_active' => true]
        );
    }

    private function createCustomer(string $name): Customer
    {
        return Customer::create([
            'customer_group_id' => 1,
            'name'              => $name . '_' . uniqid(),
            'company_name'      => 'Company ' . uniqid(),
            'email'             => 'cust_' . uniqid() . '@example.test',
            'phone_number'      => '123' . rand(1000, 9999),
            'address'           => 'Test Address',
            'city'              => 'Test City',
            'is_active'         => true,
            'opening_balance'   => 0,
        ]);
    }

    public function test_sales_read_service_enforces_warehouse_isolation(): void
    {
        $wh1 = $this->ensureWarehouse(1, 'Alpha');
        $wh2 = $this->ensureWarehouse(2, 'Beta');
        $customer = $this->createCustomer('CustSales');

        Sale::create([
            'reference_no'   => 'S-WH1-' . uniqid(),
            'user_id'        => 1,
            'customer_id'    => $customer->id,
            'warehouse_id'   => $wh1->id,
            'biller_id'      => 1,
            'item'           => 1,
            'total_qty'      => 2,
            'total_price'    => 100,
            'grand_total'    => 100,
            'paid_amount'    => 100,
            'payment_status' => 4,
            'sale_status'    => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Sale::create([
            'reference_no'   => 'S-WH2-' . uniqid(),
            'user_id'        => 1,
            'customer_id'    => $customer->id,
            'warehouse_id'   => $wh2->id,
            'biller_id'      => 1,
            'item'           => 1,
            'total_qty'      => 5,
            'total_price'    => 500,
            'grand_total'    => 500,
            'paid_amount'    => 500,
            'payment_status' => 4,
            'sale_status'    => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $service = app(SalesReadService::class);

        // Context restricted to Warehouse 1 ONLY
        $restrictedUser = new User(['id' => 10, 'role_id' => 3, 'warehouse_id' => $wh1->id, 'is_active' => true]);
        $restrictedContext = AssistantAccessContext::fromUser($restrictedUser);

        $summary = $service->summary($restrictedContext);
        $this->assertEquals(100.0, $summary['total_sales']);

        $recent = $service->recentSales($restrictedContext, 10);
        $this->assertNotEmpty($recent);
        foreach ($recent as $item) {
            $this->assertEquals($wh1->name, $item['warehouse']);
        }
    }

    public function test_customer_read_service_due_list_isolates_warehouse_debt(): void
    {
        $wh1 = $this->ensureWarehouse(1, 'Alpha');
        $wh2 = $this->ensureWarehouse(2, 'Beta');

        $customerA = $this->createCustomer('AlphaDebtor');
        $customerB = $this->createCustomer('BetaDebtor');

        // Customer A owes $75 in Wh1 (Sale 100 - Payment 25)
        $saleA = Sale::create([
            'reference_no'   => 'DUE-WH1-' . uniqid(),
            'user_id'        => 1,
            'customer_id'    => $customerA->id,
            'warehouse_id'   => $wh1->id,
            'biller_id'      => 1,
            'item'           => 1,
            'total_qty'      => 1,
            'total_price'    => 100,
            'grand_total'    => 100,
            'paid_amount'    => 25,
            'payment_status' => 1,
            'sale_status'    => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        \App\Models\Payment::create([
            'payment_reference' => 'PAY-' . uniqid(),
            'user_id'           => 1,
            'sale_id'           => $saleA->id,
            'account_id'        => 1,
            'amount'            => 25,
            'change'            => 0,
            'paying_method'     => 'Cash',
        ]);

        // Customer B owes $200 in Wh2
        Sale::create([
            'reference_no'   => 'DUE-WH2-' . uniqid(),
            'user_id'        => 1,
            'customer_id'    => $customerB->id,
            'warehouse_id'   => $wh2->id,
            'biller_id'      => 1,
            'item'           => 1,
            'total_qty'      => 1,
            'total_price'    => 250,
            'grand_total'    => 250,
            'paid_amount'    => 50,
            'payment_status' => 1,
            'sale_status'    => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $service = app(CustomerReadService::class);

        // Staff restricted to Wh1
        $staffWh1 = new User(['id' => 11, 'role_id' => 3, 'warehouse_id' => $wh1->id, 'is_active' => true]);
        $contextWh1 = AssistantAccessContext::fromUser($staffWh1);

        $dueList = $service->dueList($contextWh1, 10);
        $rows = collect($dueList['rows']);

        // Must see Customer A
        $this->assertNotNull($rows->firstWhere('customer_id', $customerA->id));
        $this->assertEquals(75.0, $rows->firstWhere('customer_id', $customerA->id)['due']);

        // MUST NOT see Customer B
        $this->assertNull(
            $rows->firstWhere('customer_id', $customerB->id),
            'PARITY SUCCESS: Customer B from Warehouse 2 was correctly filtered out for Warehouse 1 staff'
        );
    }

    public function test_business_summary_service_composes_authoritative_services(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        $service = app(BusinessSummaryReadService::class);
        $snapshot = $service->todaySnapshot($context);

        $this->assertArrayHasKey('sales', $snapshot);
        $this->assertArrayHasKey('purchases', $snapshot);
        $this->assertArrayHasKey('customer_due', $snapshot);
        $this->assertArrayHasKey('supplier_due', $snapshot);
        $this->assertArrayHasKey('low_stock_count', $snapshot);
    }
}
