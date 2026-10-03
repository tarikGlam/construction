<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Read\RestaurantReadService;
use App\Services\Read\PeopleReadService;
use App\Services\Read\ManufacturingReadService;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;
use App\Models\Table;
use App\Models\Sale;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\Production;
use Illuminate\Support\Facades\DB;

class OptionalModulesReadServicesTest extends TestCase
{
    use DatabaseTransactions;

    private RestaurantReadService $restaurantService;
    private PeopleReadService $peopleService;
    private ManufacturingReadService $manufacturingService;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");
        $this->restaurantService = app(RestaurantReadService::class);
        $this->peopleService = app(PeopleReadService::class);
        $this->manufacturingService = app(ManufacturingReadService::class);

        DB::table('warehouses')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Warehouse 1', 'address' => 'Addr 1', 'is_active' => true]
        );
        DB::table('warehouses')->updateOrInsert(
            ['id' => 2],
            ['name' => 'Warehouse 2', 'address' => 'Addr 2', 'is_active' => true]
        );
        DB::table('billers')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Main Biller', 'company_name' => 'SalePro Co', 'is_active' => true]
        );
        DB::table('customer_groups')->updateOrInsert(
            ['id' => 1],
            ['name' => 'General', 'percentage' => '0', 'is_active' => true]
        );
        DB::table('accounts')->updateOrInsert(
            ['id' => 1],
            ['account_no' => '019912229', 'name' => 'Sales Account', 'initial_balance' => 0, 'total_balance' => 0, 'is_active' => true]
        );
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

    public function test_restaurant_table_summary_and_open_orders()
    {
        $table1 = Table::create([
            'name' => 'Table T1',
            'number_of_person' => 4,
            'floor_id' => 1,
            'is_active' => true,
        ]);

        $table2 = Table::create([
            'name' => 'Table T2',
            'number_of_person' => 2,
            'floor_id' => 1,
            'is_active' => true,
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Dine-in Customer',
            'customer_group_id' => 1,
            'is_active' => true,
        ]);

        // Create an open unpaid order at Table 1
        $sale = Sale::create([
            'reference_no' => 'REST-ORDER-001',
            'user_id' => 1,
            'customer_id' => $customerId,
            'warehouse_id' => 1,
            'biller_id' => 1,
            'table_id' => $table1->id,
            'item' => 3,
            'total_qty' => 3,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_price' => 75,
            'grand_total' => 75,
            'paid_amount' => 0,
            'sale_status' => 1,
            'payment_status' => 1, // Unpaid
        ]);

        $context = $this->createDummyContext();
        $summary = $this->restaurantService->tableSummary($context);

        $this->assertTrue($summary['available']);
        $this->assertGreaterThanOrEqual(2, $summary['total_tables']);
        $this->assertGreaterThanOrEqual(1, $summary['occupied_tables']);

        $openOrders = $this->restaurantService->openOrders($context);
        $found = collect($openOrders)->firstWhere('reference_no', 'REST-ORDER-001');
        $this->assertNotNull($found);
        $this->assertEquals('Table T1', $found['table_name']);
        $this->assertEquals(75.0, $found['grand_total']);
    }

    public function test_people_headcount_attendance_and_payroll()
    {
        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Kitchen Operations',
            'is_active' => true,
        ]);

        $emp = Employee::create([
            'name' => 'Chef Maya',
            'email' => 'maya_chef@example.com',
            'phone_number' => '555-4433',
            'department_id' => $deptId,
            'warehouse_id' => 1,
            'is_active' => true,
            'is_sale_agent' => 0,
        ]);

        $context = $this->createDummyContext();
        $headcount = $this->peopleService->headcountSummary($context);

        $this->assertTrue($headcount['available']);
        $this->assertGreaterThanOrEqual(1, $headcount['total_employees']);
        $this->assertGreaterThanOrEqual(1, $headcount['active_employees']);

        // Create attendance
        Attendance::create([
            'date' => date('Y-m-d'),
            'employee_id' => $emp->id,
            'user_id' => 1,
            'checkin' => '08:30:00',
            'checkout' => '17:00:00',
            'status' => 1, // Present
        ]);

        $attendance = $this->peopleService->attendanceSummary($context, date('Y-m-d'));
        $this->assertTrue($attendance['available']);
        $this->assertGreaterThanOrEqual(1, $attendance['total_present']);

        // Create payroll
        Payroll::create([
            'reference_no' => 'PAY-TEST-001',
            'employee_id' => $emp->id,
            'account_id' => 1,
            'user_id' => 1,
            'amount' => 1200,
            'paying_method' => 'Cash',
            'month' => date('F-Y'),
            'status' => 'completed',
        ]);

        $payroll = $this->peopleService->payrollSummary($context, date('F'));
        $this->assertTrue($payroll['available']);
        $this->assertGreaterThanOrEqual(1, $payroll['total_payrolls']);
        $this->assertGreaterThanOrEqual(1200.0, $payroll['total_amount']);
    }

    public function test_manufacturing_summary_and_productions()
    {
        DB::table('productions')->insert([
            'reference_no' => 'MFG-TEST-001',
            'warehouse_id' => 1,
            'user_id' => 1,
            'item' => 2,
            'total_qty' => 100,
            'total_tax' => 0,
            'total_cost' => 1500,
            'production_cost' => 200,
            'grand_total' => 1700,
            'status' => 1, // Completed
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = $this->createDummyContext();
        $summary = $this->manufacturingService->summary($context);

        $this->assertTrue($summary['available']);
        $this->assertGreaterThanOrEqual(1, $summary['total_productions']);
        $this->assertGreaterThanOrEqual(1, $summary['completed_productions']);
        $this->assertGreaterThanOrEqual(100.0, $summary['total_quantity_produced']);

        $recent = $this->manufacturingService->recentProductions($context);
        $found = collect($recent)->firstWhere('reference_no', 'MFG-TEST-001');
        $this->assertNotNull($found);
        $this->assertEquals('Completed', $found['status_label']);
        $this->assertEquals(1700.0, $found['grand_total']);
    }

    public function test_optional_modules_respect_warehouse_isolation()
    {
        DB::table('productions')->insert([
            [
                'reference_no' => 'MFG-WH1-99',
                'warehouse_id' => 1,
                'user_id' => 1,
                'item' => 1,
                'total_qty' => 50,
                'total_tax' => 0,
                'total_cost' => 500,
                'production_cost' => 0,
                'grand_total' => 500,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'reference_no' => 'MFG-WH2-88',
                'warehouse_id' => 2,
                'user_id' => 1,
                'item' => 1,
                'total_qty' => 30,
                'total_tax' => 0,
                'total_cost' => 300,
                'production_cost' => 0,
                'grand_total' => 300,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // Scoped to Warehouse 1
        $scopeWh1 = new WarehouseScope(isRestricted: true, warehouseIds: [1]);
        $listWh1 = $this->manufacturingService->recentProductions($scopeWh1);
        $refsWh1 = array_column($listWh1, 'reference_no');
        $this->assertContains('MFG-WH1-99', $refsWh1);
        $this->assertNotContains('MFG-WH2-88', $refsWh1);

        // Scoped to Warehouse 2
        $scopeWh2 = new WarehouseScope(isRestricted: true, warehouseIds: [2]);
        $listWh2 = $this->manufacturingService->recentProductions($scopeWh2);
        $refsWh2 = array_column($listWh2, 'reference_no');
        $this->assertContains('MFG-WH2-88', $refsWh2);
        $this->assertNotContains('MFG-WH1-99', $refsWh2);
    }
}
