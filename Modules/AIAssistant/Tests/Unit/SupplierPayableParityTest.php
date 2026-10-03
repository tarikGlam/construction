<?php

namespace Modules\AIAssistant\Tests\Unit;

use App\Models\Payment;
use App\Models\Purchase;
use App\Models\ReturnPurchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Read\SupplierReadService;
use App\Services\SupplierDuePaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Tests\TestCase;

class SupplierPayableParityTest extends TestCase
{
    use DatabaseTransactions;

    private SupplierReadService $supplierReadService;
    private SupplierDuePaymentService $supplierDuePaymentService;
    private Warehouse $warehouse1;
    private Warehouse $warehouse2;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement("SET SESSION sql_mode=''");

        $this->supplierReadService = app(SupplierReadService::class);
        $this->supplierDuePaymentService = app(SupplierDuePaymentService::class);

        $this->warehouse1 = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 1],
            ['name' => 'Supplier WH 1', 'address' => 'Addr 1', 'is_active' => true]
        );
        $this->warehouse2 = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 2],
            ['name' => 'Supplier WH 2', 'address' => 'Addr 2', 'is_active' => true]
        );
    }

    private function createSupplier(string $name, float $openingBalance = 0.0): Supplier
    {
        return Supplier::create([
            'name' => $name,
            'company_name' => 'Co ' . $name,
            'email' => strtolower($name) . '_' . uniqid() . '@example.test',
            'phone_number' => '12345678',
            'address' => 'Test Address',
            'city' => 'City',
            'opening_balance' => $openingBalance,
            'is_active' => true,
        ]);
    }

    public function test_supplier_balance_matches_authoritative_due(): void
    {
        $supplier = $this->createSupplier('ParitySupA', openingBalance: 120.0);

        // Purchase: 400 total, 150 paid -> 250 due
        $purchase = Purchase::create([
            'reference_no' => 'P-PAR-1',
            'user_id' => 1,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse1->id,
            'item' => 1,
            'total_qty' => 1,
            'total_cost' => 400,
            'grand_total' => 400,
            'paid_amount' => 150,
            'status' => 1,
            'payment_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        $authoritativeDue = $this->supplierDuePaymentService->dueForSupplier($supplier->id);
        $readServiceDue = $this->supplierReadService->balance($context, $supplier->id);

        $this->assertEquals($authoritativeDue, $readServiceDue, 'SupplierReadService::balance must match authoritative dueForSupplier');
        $this->assertEquals(370.0, $readServiceDue); // 120 + 250
    }

    public function test_supplier_due_list_total_and_rows_match_authoritative_dues(): void
    {
        $s1 = $this->createSupplier('ParitySup1', openingBalance: 80.0);
        $s2 = $this->createSupplier('ParitySup2', openingBalance: 0.0);

        // Purchase for s2: 300 due
        Purchase::create([
            'reference_no' => 'P-PAR-2',
            'user_id' => 1,
            'supplier_id' => $s2->id,
            'warehouse_id' => $this->warehouse1->id,
            'item' => 1,
            'total_qty' => 1,
            'total_cost' => 300,
            'grand_total' => 300,
            'paid_amount' => 0,
            'status' => 1,
            'payment_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        $dueList = $this->supplierReadService->dueList($context, 10);
        $expectedS1 = $this->supplierDuePaymentService->dueForSupplier($s1->id);
        $expectedS2 = $this->supplierDuePaymentService->dueForSupplier($s2->id);

        $s1Row = collect($dueList['rows'])->firstWhere('supplier_id', $s1->id);
        $s2Row = collect($dueList['rows'])->firstWhere('supplier_id', $s2->id);

        $this->assertNotNull($s1Row);
        $this->assertEquals($expectedS1, $s1Row['due']);
        $this->assertNotNull($s2Row);
        $this->assertEquals($expectedS2, $s2Row['due']);
    }

    public function test_warehouse_restricted_due_matches_authoritative_scoped_due(): void
    {
        $supplier = $this->createSupplier('WarehouseScopedSup', openingBalance: 500.0);

        // Purchase in WH 1: 200
        Purchase::create([
            'reference_no' => 'P-WH1',
            'user_id' => 1,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse1->id,
            'item' => 1,
            'total_qty' => 1,
            'total_cost' => 200,
            'grand_total' => 200,
            'paid_amount' => 0,
            'status' => 1,
            'payment_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Purchase in WH 2: 400
        Purchase::create([
            'reference_no' => 'P-WH2',
            'user_id' => 1,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse2->id,
            'item' => 1,
            'total_qty' => 1,
            'total_cost' => 400,
            'grand_total' => 400,
            'paid_amount' => 0,
            'status' => 1,
            'payment_status' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $scopedContext = new WarehouseScope(
            isRestricted: true,
            warehouseIds: [$this->warehouse1->id],
            ownUserId: null
        );

        $authoritativeWh1 = $this->supplierDuePaymentService->dueForSupplier($supplier->id, [$this->warehouse1->id]);
        $readServiceWh1 = $this->supplierReadService->balance($scopedContext, $supplier->id);

        $this->assertEquals($authoritativeWh1, $readServiceWh1);
        $this->assertEquals(200.0, $readServiceWh1, 'Warehouse-restricted supplier due must only include WH 1 purchases without global opening balance');

        $dueList = $this->supplierReadService->dueList($scopedContext, 10);
        $row = collect($dueList['rows'])->firstWhere('supplier_id', $supplier->id);
        $this->assertNotNull($row);
        $this->assertEquals(200.0, $row['due']);
    }
}
