<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Read\InventoryReadService;
use App\Services\Read\PurchaseReadService;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;
use App\Models\User;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\ProductPurchase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SupplyInventoryExpansionTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryReadService $inventoryReadService;
    private PurchaseReadService $purchaseReadService;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");
        $this->inventoryReadService = app(InventoryReadService::class);
        $this->purchaseReadService = app(PurchaseReadService::class);

        DB::table('warehouses')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Warehouse 1', 'address' => 'Addr 1', 'is_active' => true]
        );
        DB::table('warehouses')->updateOrInsert(
            ['id' => 2],
            ['name' => 'Warehouse 2', 'address' => 'Addr 2', 'is_active' => true]
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

    public function test_batch_stock_returns_products_with_batches()
    {
        $product = Product::create([
            'name' => 'Batch Test Product',
            'code' => 'BTP01',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'unit_id' => 1,
            'purchase_unit_id' => 1,
            'sale_unit_id' => 1,
            'cost' => 10,
            'price' => 20,
            'qty' => 50,
            'alert_quantity' => 5,
            'is_active' => true,
        ]);

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_no' => 'BATCH-TEST-001',
            'expired_date' => Carbon::now()->addDays(60)->toDateString(),
            'qty' => 50,
        ]);

        $context = $this->createDummyContext();
        $results = $this->inventoryReadService->batchStock($context, $product->id);

        $this->assertNotEmpty($results);
        $this->assertEquals('BATCH-TEST-001', $results[0]['batch_no']);
        $this->assertEquals(50.0, $results[0]['qty']);
    }

    public function test_batch_stock_respects_warehouse_isolation()
    {
        $product = Product::create([
            'name' => 'Warehouse Scoped Batch Product',
            'code' => 'WSBP01',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'unit_id' => 1,
            'purchase_unit_id' => 1,
            'sale_unit_id' => 1,
            'cost' => 10,
            'price' => 20,
            'qty' => 30,
            'alert_quantity' => 5,
            'is_active' => true,
        ]);

        $batch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_no' => 'BATCH-WH-001',
            'expired_date' => Carbon::now()->addDays(90)->toDateString(),
            'qty' => 30,
        ]);

        // Place 20 in Warehouse 1, 10 in Warehouse 2
        DB::table('product_warehouse')->insert([
            ['product_id' => $product->id, 'product_batch_id' => $batch->id, 'warehouse_id' => 1, 'qty' => 20, 'price' => 20],
            ['product_id' => $product->id, 'product_batch_id' => $batch->id, 'warehouse_id' => 2, 'qty' => 10, 'price' => 20],
        ]);

        // Context restricted to Warehouse 1
        $contextWh1 = $this->createDummyContext(isRestricted: true, warehouseIds: [1]);
        $resultsWh1 = $this->inventoryReadService->batchStock($contextWh1, $product->id);

        $this->assertCount(1, $resultsWh1);
        $this->assertEquals(20.0, $resultsWh1[0]['qty']);

        // Context restricted to Warehouse with no access
        $contextEmpty = $this->createDummyContext(isRestricted: true, warehouseIds: []);
        $resultsEmpty = $this->inventoryReadService->batchStock($contextEmpty, $product->id);
        $this->assertEmpty($resultsEmpty);

        // Staff access restricted to own records
        $contextStaff = $this->createDummyContext(isRestricted: false, warehouseIds: [], ownUserId: 999);
        $resultsStaff = $this->inventoryReadService->batchStock($contextStaff, $product->id);
        $this->assertEmpty($resultsStaff);
    }

    public function test_batch_expiry_alerts_flags_expired_and_expiring_batches()
    {
        $product = Product::create([
            'name' => 'Expiry Alert Product',
            'code' => 'EAP01',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'unit_id' => 1,
            'purchase_unit_id' => 1,
            'sale_unit_id' => 1,
            'cost' => 10,
            'price' => 20,
            'qty' => 15,
            'alert_quantity' => 2,
            'is_active' => true,
        ]);

        // Batch already expired 5 days ago
        $expiredBatch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_no' => 'EXPIRED-BATCH-1',
            'expired_date' => Carbon::now()->subDays(5)->toDateString(),
            'qty' => 5,
        ]);

        // Batch expiring in 10 days
        $expiringBatch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_no' => 'EXPIRING-SOON-1',
            'expired_date' => Carbon::now()->addDays(10)->toDateString(),
            'qty' => 10,
        ]);

        // Batch expiring in 90 days (beyond 30 day threshold)
        $safeBatch = ProductBatch::create([
            'product_id' => $product->id,
            'batch_no' => 'SAFE-BATCH-1',
            'expired_date' => Carbon::now()->addDays(90)->toDateString(),
            'qty' => 20,
        ]);

        $context = $this->createDummyContext();
        $alerts = $this->inventoryReadService->batchExpiryAlerts($context, daysThreshold: 30, filters: ['product_id' => $product->id]);

        $this->assertCount(2, $alerts);
        $batchNos = array_column($alerts, 'batch_no');
        $this->assertContains('EXPIRED-BATCH-1', $batchNos);
        $this->assertContains('EXPIRING-SOON-1', $batchNos);
        $this->assertNotContains('SAFE-BATCH-1', $batchNos);

        $statuses = array_column($alerts, 'status');
        $this->assertContains('expired', $statuses);
        $this->assertContains('expiring_soon', $statuses);
    }

    public function test_purchase_orders_returns_orders_and_due_amounts()
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Abdul Test Supplier',
            'company_name' => 'Supply Co',
            'email' => 'abdul_supp@example.com',
            'phone_number' => '5551234',
            'address' => 'Test Address',
            'city' => 'Test City',
            'is_active' => true,
        ]);

        $purchase = Purchase::create([
            'reference_no' => 'PO-TEST-1001',
            'user_id' => 1,
            'warehouse_id' => 1,
            'supplier_id' => $supplierId,
            'item' => 2,
            'total_qty' => 10,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 100,
            'grand_total' => 100,
            'paid_amount' => 40,
            'status' => 4, // Ordered
            'payment_status' => 2, // Partial
        ]);

        $context = $this->createDummyContext();
        $orders = $this->purchaseReadService->purchaseOrders($context, status: 4);

        $this->assertNotEmpty($orders);
        $found = collect($orders)->firstWhere('reference_no', 'PO-TEST-1001');
        $this->assertNotNull($found);
        $this->assertEquals('Ordered', $found['status_label']);
        $this->assertEquals('Partial', $found['payment_status_label']);
        $this->assertEquals(60.0, $found['due_amount']);
        $this->assertEquals('Abdul Test Supplier', $found['supplier_name']);
    }

    public function test_purchase_orders_respects_warehouse_and_staff_own_access()
    {
        $pWh1 = Purchase::create([
            'reference_no' => 'PO-WH1-100',
            'user_id' => 101,
            'warehouse_id' => 1,
            'item' => 1,
            'total_qty' => 5,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 50,
            'grand_total' => 50,
            'paid_amount' => 50,
            'status' => 1,
            'payment_status' => 3,
        ]);

        $pWh2 = Purchase::create([
            'reference_no' => 'PO-WH2-200',
            'user_id' => 102,
            'warehouse_id' => 2,
            'item' => 1,
            'total_qty' => 5,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 50,
            'grand_total' => 50,
            'paid_amount' => 50,
            'status' => 1,
            'payment_status' => 3,
        ]);

        // Restricted to Warehouse 1
        $contextWh1 = $this->createDummyContext(isRestricted: true, warehouseIds: [1]);
        $ordersWh1 = $this->purchaseReadService->purchaseOrders($contextWh1, limit: 50);
        $refsWh1 = array_column($ordersWh1, 'reference_no');
        $this->assertContains('PO-WH1-100', $refsWh1);
        $this->assertNotContains('PO-WH2-200', $refsWh1);

        // Restricted to staff user 102 (own records only)
        $contextStaff = $this->createDummyContext(isRestricted: false, warehouseIds: [], ownUserId: 102);
        $ordersStaff = $this->purchaseReadService->purchaseOrders($contextStaff, limit: 50);
        $refsStaff = array_column($ordersStaff, 'reference_no');
        $this->assertContains('PO-WH2-200', $refsStaff);
        $this->assertNotContains('PO-WH1-100', $refsStaff);
    }

    public function test_pending_arrivals_identifies_unreceived_quantities()
    {
        $purchase = Purchase::create([
            'reference_no' => 'PO-ARRIVAL-01',
            'user_id' => 1,
            'warehouse_id' => 1,
            'item' => 1,
            'total_qty' => 20,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 200,
            'grand_total' => 200,
            'paid_amount' => 0,
            'status' => 2, // Partial
            'payment_status' => 1,
        ]);

        $product = Product::create([
            'name' => 'Arrival Product',
            'code' => 'ARR01',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'unit_id' => 1,
            'purchase_unit_id' => 1,
            'sale_unit_id' => 1,
            'cost' => 10,
            'price' => 20,
            'qty' => 0,
            'is_active' => true,
        ]);

        // 20 ordered, 8 received => 12 pending
        ProductPurchase::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'qty' => 20,
            'recieved' => 8,
            'purchase_unit_id' => 1,
            'net_unit_cost' => 10,
            'discount' => 0,
            'tax_rate' => 0,
            'tax' => 0,
            'total' => 200,
        ]);

        $context = $this->createDummyContext();
        $pending = $this->purchaseReadService->pendingArrivals($context);

        $found = collect($pending)->firstWhere('reference_no', 'PO-ARRIVAL-01');
        $this->assertNotNull($found);
        $this->assertEquals(20.0, $found['total_qty']);
        $this->assertEquals(8.0, $found['received_qty']);
        $this->assertEquals(12.0, $found['pending_qty']);
    }

    public function test_purchase_order_details_returns_line_items()
    {
        $purchase = Purchase::create([
            'reference_no' => 'PO-DETAIL-99',
            'user_id' => 1,
            'warehouse_id' => 1,
            'item' => 1,
            'total_qty' => 15,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_cost' => 150,
            'grand_total' => 150,
            'paid_amount' => 50,
            'status' => 2,
            'payment_status' => 2,
            'note' => 'Fragile items included',
        ]);

        $product = Product::create([
            'name' => 'Line Item Product',
            'code' => 'LIP99',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'unit_id' => 1,
            'purchase_unit_id' => 1,
            'sale_unit_id' => 1,
            'cost' => 10,
            'price' => 20,
            'qty' => 0,
            'is_active' => true,
        ]);

        ProductPurchase::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'qty' => 15,
            'recieved' => 5,
            'purchase_unit_id' => 1,
            'net_unit_cost' => 10,
            'discount' => 0,
            'tax_rate' => 0,
            'tax' => 0,
            'total' => 150,
        ]);

        $context = $this->createDummyContext();
        $details = $this->purchaseReadService->purchaseOrderDetails($context, 'PO-DETAIL-99');

        $this->assertNotNull($details);
        $this->assertEquals('PO-DETAIL-99', $details['reference_no']);
        $this->assertEquals('Fragile items included', $details['note']);
        $this->assertEquals(100.0, $details['due_amount']);
        $this->assertCount(1, $details['items']);
        $this->assertEquals('Line Item Product', $details['items'][0]['product_name']);
        $this->assertEquals(15.0, $details['items'][0]['qty']);
        $this->assertEquals(5.0, $details['items'][0]['recieved']);
        $this->assertEquals(10.0, $details['items'][0]['pending_qty']);
    }

    public function test_warehouse_scope_value_object_interop()
    {
        $scope = new WarehouseScope(isRestricted: true, warehouseIds: [1]);

        $batchResults = $this->inventoryReadService->batchStock($scope);
        $this->assertIsArray($batchResults);

        $expiryAlerts = $this->inventoryReadService->batchExpiryAlerts($scope);
        $this->assertIsArray($expiryAlerts);

        $purchaseOrders = $this->purchaseReadService->purchaseOrders($scope);
        $this->assertIsArray($purchaseOrders);

        $pending = $this->purchaseReadService->pendingArrivals($scope);
        $this->assertIsArray($pending);
    }
}
