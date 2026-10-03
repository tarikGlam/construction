<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Read\QuotationReadService;
use App\Services\Read\CustomerReadService;
use App\Services\Read\SalesReadService;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Quotation;
use App\Models\ProductQuotation;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class SalesCrmExpansionTest extends TestCase
{
    use DatabaseTransactions;

    private QuotationReadService $quotationService;
    private CustomerReadService $customerService;
    private SalesReadService $salesService;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");
        $this->quotationService = app(QuotationReadService::class);
        $this->customerService = app(CustomerReadService::class);
        $this->salesService = app(SalesReadService::class);
    }

    private function createDummyContext(
        bool $isRestricted = false,
        array $warehouseIds = [],
        ?int $ownUserId = null
    ): AssistantAccessContext {
        return new AssistantAccessContext(
            user: null,
            tenantId: null,
            warehouseClassification: $isRestricted ? 'warehouse_restricted' : 'all_warehouses',
            isGlobalWarehouseAccess: !$isRestricted,
            isRestrictedWarehouseAccess: $isRestricted,
            allowedWarehouseIds: $warehouseIds,
            isPortalUser: false,
            portalCustomerId: null,
            ownUserId: $ownUserId,
            pageContext: []
        );
    }

    public function test_quotation_summary_and_list()
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Quote Test Customer',
            'customer_group_id' => 1,
            'phone_number' => '123456',
            'email' => 'quote@example.com',
            'is_active' => true,
        ]);

        $q1 = Quotation::create([
            'reference_no' => 'QR-TEST-001',
            'user_id' => 1,
            'biller_id' => 1,
            'warehouse_id' => 1,
            'customer_id' => $customerId,
            'item' => 2,
            'total_qty' => 5,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 500,
            'grand_total' => 500,
            'quotation_status' => 1, // Pending
        ]);

        $q2 = Quotation::create([
            'reference_no' => 'QR-TEST-002',
            'user_id' => 1,
            'biller_id' => 1,
            'warehouse_id' => 1,
            'customer_id' => $customerId,
            'item' => 1,
            'total_qty' => 2,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 200,
            'grand_total' => 200,
            'quotation_status' => 2, // Sent
        ]);

        $context = $this->createDummyContext();
        $summary = $this->quotationService->summary($context);

        $this->assertGreaterThanOrEqual(2, $summary['total_quotations']);
        $this->assertGreaterThanOrEqual(700.0, $summary['total_amount']);
        $this->assertGreaterThanOrEqual(1, $summary['pending_quotations']);
        $this->assertGreaterThanOrEqual(1, $summary['sent_quotations']);

        $list = $this->quotationService->quotations($context, status: 1);
        $found = collect($list)->firstWhere('reference_no', 'QR-TEST-001');
        $this->assertNotNull($found);
        $this->assertEquals('Pending', $found['status_label']);
        $this->assertEquals('Quote Test Customer', $found['customer_name']);
    }

    public function test_quotation_details_with_items()
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Details Quote Customer',
            'customer_group_id' => 1,
            'phone_number' => '998877',
            'email' => 'details_q@example.com',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Quoted Product',
            'code' => 'QP01',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'unit_id' => 1,
            'purchase_unit_id' => 1,
            'sale_unit_id' => 1,
            'cost' => 50,
            'price' => 100,
            'qty' => 10,
            'is_active' => true,
        ]);

        $quote = Quotation::create([
            'reference_no' => 'QR-DET-999',
            'user_id' => 1,
            'biller_id' => 1,
            'warehouse_id' => 1,
            'customer_id' => $customerId,
            'item' => 1,
            'total_qty' => 3,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 300,
            'grand_total' => 300,
            'quotation_status' => 2,
            'note' => 'Valid for 15 days',
        ]);

        ProductQuotation::create([
            'quotation_id' => $quote->id,
            'product_id' => $product->id,
            'qty' => 3,
            'sale_unit_id' => 1,
            'net_unit_price' => 100,
            'discount' => 0,
            'tax_rate' => 0,
            'tax' => 0,
            'total' => 300,
        ]);

        $context = $this->createDummyContext();
        $details = $this->quotationService->quotationDetails($context, 'QR-DET-999');

        $this->assertNotNull($details);
        $this->assertEquals('QR-DET-999', $details['reference_no']);
        $this->assertEquals('Sent', $details['status_label']);
        $this->assertEquals('Valid for 15 days', $details['note']);
        $this->assertCount(1, $details['items']);
        $this->assertEquals('Quoted Product', $details['items'][0]['product_name']);
        $this->assertEquals(3.0, $details['items'][0]['qty']);
        $this->assertEquals(300.0, $details['items'][0]['total']);
    }

    public function test_customer_profile_and_purchase_history()
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'CRM Profile Customer',
            'company_name' => 'Acme Corp',
            'email' => 'acme@example.com',
            'phone_number' => '555-4321',
            'city' => 'Metropolis',
            'customer_group_id' => 1,
            'is_active' => true,
        ]);

        $sale = Sale::create([
            'reference_no' => 'POS-CRM-101',
            'user_id' => 1,
            'customer_id' => $customerId,
            'warehouse_id' => 1,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 2,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 450,
            'grand_total' => 450,
            'paid_amount' => 300,
            'sale_status' => 1,
            'payment_status' => 3, // Partial
        ]);

        $context = $this->createDummyContext();
        $profile = $this->customerService->customerProfile($context, $customerId);

        $this->assertNotNull($profile);
        $this->assertEquals('CRM Profile Customer', $profile['name']);
        $this->assertEquals('Acme Corp', $profile['company_name']);
        $this->assertEquals(1, $profile['total_orders']);
        $this->assertEquals(450.0, $profile['lifetime_spend']);
        $this->assertCount(1, $profile['recent_purchases']);
        $this->assertEquals('POS-CRM-101', $profile['recent_purchases'][0]['reference_no']);
        $this->assertEquals(150.0, $profile['recent_purchases'][0]['due_amount']);
    }

    public function test_sales_agent_performance_metrics()
    {
        // Create an employee as a sales agent
        $employee = Employee::create([
            'name' => 'Jason Agent',
            'email' => 'jason_agent@example.com',
            'phone_number' => '555-9876',
            'department_id' => 1,
            'is_active' => true,
            'is_sale_agent' => 1,
            'sale_commission_percent' => 5.0,
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Buyer for Agent',
            'customer_group_id' => 1,
            'is_active' => true,
        ]);

        // Sale with explicit sales_agent_id
        Sale::create([
            'reference_no' => 'POS-AGENT-01',
            'user_id' => 1,
            'sales_agent_id' => $employee->id,
            'customer_id' => $customerId,
            'warehouse_id' => 1,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 10,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 1000,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'sale_status' => 1,
            'payment_status' => 4,
        ]);

        Sale::create([
            'reference_no' => 'POS-AGENT-02',
            'user_id' => 1,
            'sales_agent_id' => $employee->id,
            'customer_id' => $customerId,
            'warehouse_id' => 1,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 5,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 500,
            'grand_total' => 500,
            'paid_amount' => 500,
            'sale_status' => 1,
            'payment_status' => 4,
        ]);

        $context = $this->createDummyContext();
        $performance = $this->salesService->agentPerformance($context, $employee->id);

        $this->assertNotEmpty($performance);
        $agentRecord = collect($performance)->firstWhere('agent_id', $employee->id);
        $this->assertNotNull($agentRecord);
        $this->assertEquals('Jason Agent', $agentRecord['agent_name']);
        $this->assertEquals(2, $agentRecord['sales_count']);
        $this->assertEquals(1500.0, $agentRecord['total_revenue']);
        $this->assertEquals(75.0, $agentRecord['estimated_commission']); // 5% of 1500
    }

    public function test_crm_queries_respect_warehouse_and_staff_scoping()
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Isolation Customer',
            'customer_group_id' => 1,
            'is_active' => true,
        ]);

        $qWh1 = Quotation::create([
            'reference_no' => 'QR-WH1-10',
            'user_id' => 201,
            'biller_id' => 1,
            'warehouse_id' => 1,
            'customer_id' => $customerId,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 100,
            'grand_total' => 100,
            'quotation_status' => 1,
        ]);

        $qWh2 = Quotation::create([
            'reference_no' => 'QR-WH2-20',
            'user_id' => 202,
            'biller_id' => 1,
            'warehouse_id' => 2,
            'customer_id' => $customerId,
            'item' => 1,
            'total_qty' => 1,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 200,
            'grand_total' => 200,
            'quotation_status' => 1,
        ]);

        // Restricted to Warehouse 1
        $scopeWh1 = new WarehouseScope(isRestricted: true, warehouseIds: [1]);
        $quotesWh1 = $this->quotationService->quotations($scopeWh1);
        $refsWh1 = array_column($quotesWh1, 'reference_no');
        $this->assertContains('QR-WH1-10', $refsWh1);
        $this->assertNotContains('QR-WH2-20', $refsWh1);

        // Staff access restricted to user 202
        $scopeStaff = new WarehouseScope(isRestricted: false, warehouseIds: [], ownUserId: 202);
        $quotesStaff = $this->quotationService->quotations($scopeStaff);
        $refsStaff = array_column($quotesStaff, 'reference_no');
        $this->assertContains('QR-WH2-20', $refsStaff);
        $this->assertNotContains('QR-WH1-10', $refsStaff);
    }
}
