<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Returns;
use App\Models\Sale;
use App\Models\SaleExchange;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Read\SalesReadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Skills\SalesSummarySkill;
use Modules\AIAssistant\Skills\TopProductsSkill;
use Tests\TestCase;

class SalesLifecycleAdversarialParityTest extends TestCase
{
    use DatabaseTransactions;

    private SalesReadService $salesReadService;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private Customer $customer;
    private Product $product1;
    private Product $product2;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");

        $this->salesReadService = app(SalesReadService::class);

        $this->warehouseA = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 1],
            ['name' => 'Sales WH A', 'address' => 'Addr A', 'is_active' => true]
        );
        $this->warehouseB = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 2],
            ['name' => 'Sales WH B', 'address' => 'Addr B', 'is_active' => true]
        );

        $group = CustomerGroup::firstOrCreate(
            ['name' => 'Sales Group'],
            ['percentage' => '0', 'is_active' => true]
        );

        $this->customer = Customer::create([
            'name' => 'Sales Customer',
            'customer_group_id' => $group->id,
            'company_name' => 'Sales Co',
            'email' => 'sales_cust_' . uniqid() . '@example.test',
            'phone_number' => '555-1234',
            'address' => '100 Sales St',
            'city' => 'Metropolis',
            'is_active' => true,
        ]);

        $this->product1 = Product::create([
            'name' => 'Adv Alpha Product',
            'code' => 'ADV-P1-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => 1,
            'unit_id' => 1,
            'cost' => 50.0,
            'price' => 100.0,
            'qty' => 500,
            'is_active' => true,
        ]);

        $this->product2 = Product::create([
            'name' => 'Adv Beta Product',
            'code' => 'ADV-P2-' . uniqid(),
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'category_id' => 1,
            'unit_id' => 1,
            'cost' => 100.0,
            'price' => 200.0,
            'qty' => 300,
            'is_active' => true,
        ]);

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

        $permId = DB::table('permissions')->where('name', 'sales-index')->value('id');
        if (!$permId) {
            $permId = DB::table('permissions')->insertGetId(['name' => 'sales-index', 'guard_name' => 'web']);
        }
        DB::table('role_has_permissions')->updateOrInsert(['role_id' => 3, 'permission_id' => $permId]);

        $this->adminUser = User::create([
            'name' => 'Sales Admin',
            'email' => 'sales_admin_' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role_id' => 1,
            'warehouse_id' => $this->warehouseA->id,
            'is_active' => true,
        ]);
        $this->actingAs($this->adminUser);
    }

    private function createSaleWithItems(
        int $warehouseId,
        array $items,
        float $paidAmount,
        array $extraAttributes = [],
        ?string $date = null
    ): Sale {
        $date ??= Carbon::today()->toDateTimeString();
        $grandTotal = 0.0;
        $totalQty = 0.0;

        foreach ($items as $item) {
            $grandTotal += (float) ($item['qty'] * $item['price']);
            $totalQty += (float) $item['qty'];
        }

        $saleData = array_merge([
            'reference_no' => 'SALE-' . uniqid(),
            'user_id' => $this->adminUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $warehouseId,
            'biller_id' => 1,
            'item' => count($items),
            'total_qty' => $totalQty,
            'total_price' => $grandTotal,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'sale_status' => 1,
            'payment_status' => $paidAmount >= $grandTotal ? 2 : 1,
            'created_at' => $date,
            'updated_at' => $date,
        ], $extraAttributes);

        $sale = Sale::create($saleData);

        foreach ($items as $item) {
            DB::table('product_sales')->insert([
                'sale_id' => $sale->id,
                'product_id' => $item['product']->id,
                'qty' => $item['qty'],
                'sale_unit_id' => 1,
                'net_unit_price' => $item['price'],
                'discount' => 0,
                'tax_rate' => 0,
                'tax' => 0,
                'total' => $item['qty'] * $item['price'],
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }

        return $sale;
    }

    public function test_adversarial_sales_lifecycle_metrics_parity(): void
    {
        $today = Carbon::today()->toDateString();
        $now = Carbon::today()->setTime(12, 0, 0)->toDateTimeString();

        // 1. Warehouse A: Sale A1 (2 units of P1 @ 100 = 200, paid 200)
        $saleA1 = $this->createSaleWithItems(
            $this->warehouseA->id,
            [['product' => $this->product1, 'qty' => 2, 'price' => 100.0]],
            paidAmount: 200.0,
            date: $now
        );
        Payment::create([
            'payment_reference' => 'PAY-A1-' . uniqid(),
            'sale_id' => $saleA1->id,
            'user_id' => $this->adminUser->id,
            'amount' => 200.0,
            'paying_method' => 'Cash',
            'payment_at' => $now,
        ]);
        // Partial Return on Sale A1: 1 unit of P1 = 100.0 returned
        $returnA1 = Returns::create([
            'reference_no' => 'RET-A1-' . uniqid(),
            'user_id' => $this->adminUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'sale_id' => $saleA1->id,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 100.0,
            'grand_total' => 100.0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Refund on Return A1: 50.0
        Payment::create([
            'payment_reference' => 'REF-A1-' . uniqid(),
            'sale_id' => $saleA1->id,
            'return_id' => $returnA1->id,
            'user_id' => $this->adminUser->id,
            'amount' => 50.0,
            'paying_method' => 'Cash',
            'payment_at' => $now,
        ]);

        // 2. Warehouse A: Sale A2 (3 units of P2 @ 200 = 600, paid 300 -> due 300)
        $this->createSaleWithItems(
            $this->warehouseA->id,
            [['product' => $this->product2, 'qty' => 3, 'price' => 200.0]],
            paidAmount: 300.0,
            date: $now
        );

        // 3. Warehouse A: Sale A3 (1 unit of P1 @ 100 = 100, paid 100) with Full Return (100)
        $saleA3 = $this->createSaleWithItems(
            $this->warehouseA->id,
            [['product' => $this->product1, 'qty' => 1, 'price' => 100.0]],
            paidAmount: 100.0,
            date: $now
        );
        Returns::create([
            'reference_no' => 'RET-A3-' . uniqid(),
            'user_id' => $this->adminUser->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'sale_id' => $saleA3->id,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 100.0,
            'grand_total' => 100.0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 4. Warehouse A: Sale A4 (Exchange: 2 units of P1 @ 100 + 50 extra = 250, paid 250)
        $saleA4 = $this->createSaleWithItems(
            $this->warehouseA->id,
            [['product' => $this->product1, 'qty' => 2, 'price' => 100.0]],
            paidAmount: 250.0,
            extraAttributes: ['grand_total' => 250.0, 'total_price' => 250.0],
            date: $now
        );
        SaleExchange::create([
            'reference_no' => 'EXC-A4-' . uniqid(),
            'sale_id' => $saleA4->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'biller_id' => 1,
            'user_id' => $this->adminUser->id,
            'item' => 1,
            'total_qty' => 1,
            'grand_total' => 250.0,
            'amount' => 50.0,
            'created_at' => $now,
        ]);

        // 5. Inactive / Draft / Deleted / Voided / Reversed / Opening Balance Sales in Warehouse A (MUST BE EXCLUDED)
        $this->createSaleWithItems($this->warehouseA->id, [['product' => $this->product1, 'qty' => 10, 'price' => 100.0]], 1000.0, ['sale_status' => 3]);
        $deletedSale = $this->createSaleWithItems($this->warehouseA->id, [['product' => $this->product2, 'qty' => 5, 'price' => 200.0]], 1000.0);
        $deletedSale->delete();
        $this->createSaleWithItems($this->warehouseA->id, [['product' => $this->product1, 'qty' => 8, 'price' => 100.0]], 800.0, ['voided_at' => $now]);
        $this->createSaleWithItems($this->warehouseA->id, [['product' => $this->product2, 'qty' => 4, 'price' => 200.0]], 800.0, ['accounting_status' => 'reversed']);
        $this->createSaleWithItems($this->warehouseA->id, [['product' => $this->product1, 'qty' => 2, 'price' => 100.0]], 200.0, ['sale_type' => 'Opening balance']);

        // 6. Warehouse B: Sale B1 (4 units of P1 @ 100 = 400, 1 unit of P2 @ 200 = 200 -> total 600, paid 200 -> due 400)
        $this->createSaleWithItems(
            $this->warehouseB->id,
            [
                ['product' => $this->product1, 'qty' => 4, 'price' => 100.0],
                ['product' => $this->product2, 'qty' => 1, 'price' => 200.0],
            ],
            paidAmount: 200.0,
            date: $now
        );

        // ==========================================
        // INDEPENDENT CALCULATION OF AUTHORITATIVE METRICS
        // ==========================================
        // Warehouse A:
        //   Orders = 4 (A1, A2, A3, A4)
        //   Gross Sales = 200 + 600 + 100 + 250 = 1150.00
        //   Returned Amount = 100 (A1) + 100 (A3) = 200.00
        //   Net Sales = 1150 - 200 = 950.00
        //   Sale Due = (600 - 300) = 300.00
        //   Top Products:
        //     P1 sold qty = 2 (A1) + 1 (A3) + 2 (A4) = 5.0 units, revenue = 200 + 100 + 200 = 500.00
        //     P2 sold qty = 3 (A2) = 3.0 units, revenue = 600.00
        //
        // Global (Warehouse A + Warehouse B):
        //   Orders = 4 + 1 = 5
        //   Gross Sales = 1150 + 600 = 1750.00
        //   Returned Amount = 200.00
        //   Net Sales = 1750 - 200 = 1550.00
        //   Sale Due = 300 (WH A) + 400 (WH B) = 700.00
        //   Top Products:
        //     P1 sold qty = 5 + 4 = 9.0 units, revenue = 500 + 400 = 900.00
        //     P2 sold qty = 3 + 1 = 4.0 units, revenue = 600 + 200 = 800.00

        $globalContext = AssistantAccessContext::fromUser($this->adminUser);
        $summaryGlobal = $this->salesReadService->summary($globalContext, $today, $today);

        // Verify Global Summary Metrics
        $this->assertEquals(5, $summaryGlobal['total_orders'], 'Global orders count mismatch');
        $this->assertEquals(1750.0, $summaryGlobal['total_sales'], 'Global gross sales mismatch');
        $this->assertEquals(200.0, $summaryGlobal['returned_amount'], 'Global returned amount mismatch');
        $this->assertEquals(1550.0, $summaryGlobal['net_sales'], 'Global net sales mismatch');
        $this->assertEquals(700.0, $summaryGlobal['due_amount'], 'Global sale due mismatch');

        // Verify Global Top Products
        $topGlobal = $this->salesReadService->topSellingProducts($globalContext, 10, $today, $today);
        $topP1 = collect($topGlobal)->firstWhere('name', $this->product1->name);
        $topP2 = collect($topGlobal)->firstWhere('name', $this->product2->name);

        $this->assertNotNull($topP1);
        $this->assertEquals(9.0, $topP1['qty'], 'P1 gross sold quantity mismatch');
        $this->assertEquals(900.0, $topP1['revenue'], 'P1 gross revenue mismatch');

        $this->assertNotNull($topP2);
        $this->assertEquals(4.0, $topP2['qty'], 'P2 gross sold quantity mismatch');
        $this->assertEquals(800.0, $topP2['revenue'], 'P2 gross revenue mismatch');

        // Verify Warehouse A Restricted Summary
        $staffWhA = User::create([
            'name' => 'Sales Staff A',
            'email' => 'sales_staff_a_' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role_id' => 3,
            'warehouse_id' => $this->warehouseA->id,
            'is_active' => true,
        ]);
        $staffAContext = AssistantAccessContext::fromUser($staffWhA);

        $summaryWhA = $this->salesReadService->summary($staffAContext, $today, $today);
        $this->assertEquals(4, $summaryWhA['total_orders'], 'WH A orders count mismatch');
        $this->assertEquals(1150.0, $summaryWhA['total_sales'], 'WH A gross sales mismatch');
        $this->assertEquals(200.0, $summaryWhA['returned_amount'], 'WH A returned amount mismatch');
        $this->assertEquals(950.0, $summaryWhA['net_sales'], 'WH A net sales mismatch');
        $this->assertEquals(300.0, $summaryWhA['due_amount'], 'WH A sale due mismatch');

        // Verify Warehouse A Restricted Top Products
        $topWhA = $this->salesReadService->topSellingProducts($staffAContext, 10, $today, $today);
        $topP1A = collect($topWhA)->firstWhere('name', $this->product1->name);
        $topP2A = collect($topWhA)->firstWhere('name', $this->product2->name);

        $this->assertNotNull($topP1A);
        $this->assertEquals(5.0, $topP1A['qty'], 'WH A P1 sold quantity mismatch');
        $this->assertEquals(500.0, $topP1A['revenue'], 'WH A P1 revenue mismatch');

        $this->assertNotNull($topP2A);
        $this->assertEquals(3.0, $topP2A['qty'], 'WH A P2 sold quantity mismatch');
        $this->assertEquals(600.0, $topP2A['revenue'], 'WH A P2 revenue mismatch');

        // ==========================================
        // VERIFY ASSISTANT SKILLS PARITY
        // ==========================================
        $salesSkill = app(SalesSummarySkill::class);
        $topProductsSkill = app(TopProductsSkill::class);

        // SalesSummarySkill Global
        $salesResponse = $salesSkill->handle(
            new AssistantMessageData('user', 'sales summary'),
            new AssistantContextData(userId: $this->adminUser->id, accessContext: $globalContext)
        );
        $cards = collect($salesResponse->cards);
        $this->assertEquals(1750.0, $cards->firstWhere('title', __('db.ai_assistant_card_gross_total'))['value']);
        $this->assertEquals(700.0, $cards->firstWhere('title', __('db.ai_assistant_card_due_amount'))['value']);

        // TopProductsSkill Global
        $topResponse = $topProductsSkill->handle(
            new AssistantMessageData('user', 'top products'),
            new AssistantContextData(userId: $this->adminUser->id, accessContext: $globalContext)
        );
        $topRow1 = collect($topResponse->table['rows'])->first(fn($r) => str_contains($r[0], $this->product1->name));
        $this->assertNotNull($topRow1);
        $this->assertEquals(9.0, (float) $topRow1[1]); // quantity
        $this->assertEquals(900.0, (float) $topRow1[2]); // revenue
    }
}
