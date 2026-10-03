<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Read\LandedCostReadService;
use App\Models\ImportBatch;
use App\Models\ImportBatchCost;
use App\Models\Purchase;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Illuminate\Support\Facades\DB;

class LandedCostReadServiceTest extends TestCase
{
    use DatabaseTransactions;

    private LandedCostReadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");
        $this->service = app(LandedCostReadService::class);
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

    public function test_is_available_returns_true_when_tables_exist()
    {
        $this->assertTrue($this->service->isAvailable());
    }

    public function test_summary_aggregates_import_batches_and_costs()
    {
        ImportBatch::create([
            'batch_number' => 'IB-TEST-001',
            'title' => 'Shipment Alpha',
            'warehouse_id' => 1,
            'base_currency_id' => 1,
            'status' => 'finalized',
            'is_locked' => 1,
            'allocation_method' => 'purchase_value',
            'total_goods_cost' => 5000,
            'total_landed_cost' => 1200,
            'total_cost' => 6200,
            'created_by' => 1,
        ]);

        ImportBatch::create([
            'batch_number' => 'IB-TEST-002',
            'title' => 'Shipment Beta',
            'warehouse_id' => 1,
            'base_currency_id' => 1,
            'status' => 'draft',
            'is_locked' => 0,
            'allocation_method' => 'purchase_value',
            'total_goods_cost' => 3000,
            'total_landed_cost' => 500,
            'total_cost' => 3500,
            'created_by' => 1,
        ]);

        $context = $this->createDummyContext();
        $summary = $this->service->summary($context);

        $this->assertTrue($summary['available']);
        $this->assertGreaterThanOrEqual(2, $summary['total_batches']);
        $this->assertGreaterThanOrEqual(1, $summary['finalized_batches']);
        $this->assertGreaterThanOrEqual(8000.0, $summary['total_goods_cost']);
        $this->assertGreaterThanOrEqual(1700.0, $summary['total_landed_cost']);
        $this->assertGreaterThanOrEqual(9700.0, $summary['total_combined_cost']);
    }

    public function test_batches_respects_warehouse_isolation()
    {
        ImportBatch::create([
            'batch_number' => 'IB-WH1-001',
            'title' => 'Warehouse 1 Container',
            'warehouse_id' => 1,
            'base_currency_id' => 1,
            'status' => 'finalized',
            'is_locked' => 1,
            'allocation_method' => 'purchase_value',
            'total_goods_cost' => 2000,
            'total_landed_cost' => 400,
            'total_cost' => 2400,
            'created_by' => 1,
        ]);

        ImportBatch::create([
            'batch_number' => 'IB-WH2-002',
            'title' => 'Warehouse 2 Container',
            'warehouse_id' => 2,
            'base_currency_id' => 1,
            'status' => 'draft',
            'is_locked' => 0,
            'allocation_method' => 'purchase_value',
            'total_goods_cost' => 1000,
            'total_landed_cost' => 200,
            'total_cost' => 1200,
            'created_by' => 1,
        ]);

        // Restricted to Warehouse 1
        $contextWh1 = $this->createDummyContext(isRestricted: true, warehouseIds: [1]);
        $batchesWh1 = $this->service->batches($contextWh1, limit: 50);
        $batchNums = array_column($batchesWh1, 'batch_number');
        $this->assertContains('IB-WH1-001', $batchNums);
        $this->assertNotContains('IB-WH2-002', $batchNums);

        // Value object interop
        $scopeWh2 = new WarehouseScope(isRestricted: true, warehouseIds: [2]);
        $batchesWh2 = $this->service->batches($scopeWh2, limit: 50);
        $batchNums2 = array_column($batchesWh2, 'batch_number');
        $this->assertContains('IB-WH2-002', $batchNums2);
        $this->assertNotContains('IB-WH1-001', $batchNums2);
    }

    public function test_batch_details_returns_purchases_and_costs()
    {
        $batch = ImportBatch::create([
            'batch_number' => 'IB-DETAIL-001',
            'title' => 'Detailed Container',
            'warehouse_id' => 1,
            'base_currency_id' => 1,
            'status' => 'draft',
            'is_locked' => 0,
            'allocation_method' => 'purchase_value',
            'total_goods_cost' => 4000,
            'total_landed_cost' => 800,
            'total_cost' => 4800,
            'created_by' => 1,
        ]);

        // Create linked purchase
        $purchase = Purchase::create([
            'reference_no' => 'PO-IB-01',
            'user_id' => 1,
            'warehouse_id' => 1,
            'item' => 1,
            'total_qty' => 100,
            'total_cost' => 4000,
            'grand_total' => 4000,
            'paid_amount' => 4000,
            'status' => 1,
            'payment_status' => 3,
            'import_batch_id' => $batch->id,
        ]);

        // Create landed cost
        ImportBatchCost::create([
            'import_batch_id' => $batch->id,
            'cost_type' => 'Customs Duty',
            'original_amount' => 500,
            'currency_id' => 1,
            'exchange_rate' => 1,
            'base_amount' => 500,
            'notes' => 'Customs clearing port',
        ]);

        ImportBatchCost::create([
            'import_batch_id' => $batch->id,
            'cost_type' => 'Freight Shipping',
            'original_amount' => 300,
            'currency_id' => 1,
            'exchange_rate' => 1,
            'base_amount' => 300,
            'notes' => 'Ocean freight',
        ]);

        $context = $this->createDummyContext();
        $details = $this->service->batchDetails($context, 'IB-DETAIL-001');

        $this->assertNotNull($details);
        $this->assertEquals('IB-DETAIL-001', $details['batch_number']);
        $this->assertEquals('Detailed Container', $details['title']);
        $this->assertCount(1, $details['purchases']);
        $this->assertEquals('PO-IB-01', $details['purchases'][0]['reference_no']);
        $this->assertCount(2, $details['costs']);
        $costTypes = array_column($details['costs'], 'cost_type');
        $this->assertContains('Customs Duty', $costTypes);
        $this->assertContains('Freight Shipping', $costTypes);
    }

    public function test_batch_profitability_denies_unauthorized_warehouse()
    {
        $batch = ImportBatch::create([
            'batch_number' => 'IB-UNAUTH-001',
            'title' => 'Secret Container',
            'warehouse_id' => 99,
            'base_currency_id' => 1,
            'status' => 'draft',
            'is_locked' => 0,
            'allocation_method' => 'purchase_value',
            'total_goods_cost' => 1000,
            'total_landed_cost' => 200,
            'total_cost' => 1200,
            'created_by' => 1,
        ]);

        $context = $this->createDummyContext(isRestricted: true, warehouseIds: [1, 2]);
        $profitability = $this->service->batchProfitability($context, $batch->id);

        $this->assertNull($profitability);
    }

    public function test_structured_unavailability_when_disabled()
    {
        $mockService = new class extends LandedCostReadService {
            public function isAvailable(): bool {
                return false;
            }
        };

        $context = $this->createDummyContext();
        $summary = $mockService->summary($context);

        $this->assertFalse($summary['available']);
        $this->assertStringContainsString('not active', $summary['reason']);
        $this->assertEquals([], $mockService->batches($context));
        $this->assertNull($mockService->batchDetails($context, 1));
        $this->assertNull($mockService->batchProfitability($context, 1));
    }
}
