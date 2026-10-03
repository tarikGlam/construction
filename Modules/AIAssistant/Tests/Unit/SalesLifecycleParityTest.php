<?php

namespace Modules\AIAssistant\Tests\Unit;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Read\SalesReadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Tests\TestCase;

class SalesLifecycleParityTest extends TestCase
{
    use DatabaseTransactions;

    private SalesReadService $salesReadService;
    private Warehouse $warehouse;
    private Customer $customer;
    private Product $product;
    private AssistantAccessContext $adminContext;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");

        $this->salesReadService = app(SalesReadService::class);

        $this->warehouse = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 1],
            ['name' => 'Main Warehouse', 'address' => '123 Main', 'is_active' => true]
        );

        $group = CustomerGroup::firstOrCreate(
            ['name' => 'General'],
            ['percentage' => '0', 'is_active' => true]
        );

        $this->customer = Customer::firstOrCreate(
            ['email' => 'sales_lifecycle_test@example.com'],
            [
                'name' => 'Lifecycle Customer',
                'customer_group_id' => $group->id,
                'company_name' => 'Test Co',
                'phone_number' => '99887766',
                'address' => 'Test Addr',
                'city' => 'Test City',
                'is_active' => true,
            ]
        );

        $this->product = Product::firstOrCreate(
            ['code' => 'LC-PROD-1'],
            [
                'name' => 'Lifecycle Product',
                'type' => 'standard',
                'barcode_symbology' => 'code128',
                'brand_id' => null,
                'category_id' => 1,
                'unit_id' => 1,
                'purchase_unit_id' => 1,
                'sale_unit_id' => 1,
                'cost' => 50,
                'price' => 100,
                'qty' => 50,
                'alert_quantity' => 5,
                'is_active' => true,
            ]
        );

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $this->adminContext = AssistantAccessContext::fromUser($admin);
    }

    private function createSaleWithItem(array $saleAttributes, float $qty = 1.0, float $total = 100.0): int
    {
        $today = Carbon::today()->toDateString();
        $saleId = DB::table('sales')->insertGetId(array_merge([
            'reference_no' => 'SR-' . uniqid(),
            'user_id' => 1,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => $qty,
            'total_price' => $total,
            'grand_total' => $total,
            'paid_amount' => $total,
            'payment_status' => 4,
            'sale_status' => 1, // Active completed
            'created_at' => $today . ' 12:00:00',
            'updated_at' => $today . ' 12:00:00',
        ], $saleAttributes));

        DB::table('product_sales')->insert([
            'sale_id' => $saleId,
            'product_id' => $this->product->id,
            'qty' => $qty,
            'sale_unit_id' => 1,
            'net_unit_price' => $total / $qty,
            'discount' => 0,
            'tax_rate' => 0,
            'tax' => 0,
            'total' => $total,
            'created_at' => $today . ' 12:00:00',
            'updated_at' => $today . ' 12:00:00',
        ]);

        return $saleId;
    }

    public function test_sales_summary_strictly_excludes_draft_deleted_voided_and_reversed(): void
    {
        $today = Carbon::today()->toDateString();

        // Active sale: 100
        $this->createSaleWithItem(['grand_total' => 100, 'paid_amount' => 100], 1, 100);

        // Draft sale (sale_status = 3): 200
        $this->createSaleWithItem(['grand_total' => 200, 'paid_amount' => 200, 'sale_status' => 3], 2, 200);

        // Soft-deleted sale: 300
        $this->createSaleWithItem(['grand_total' => 300, 'paid_amount' => 300, 'deleted_at' => Carbon::now()], 3, 300);

        // Voided sale via voided_at: 400
        $this->createSaleWithItem(['grand_total' => 400, 'paid_amount' => 400, 'voided_at' => Carbon::now()], 4, 400);

        // Reversed sale via accounting_status = 'reversed': 500
        $this->createSaleWithItem(['grand_total' => 500, 'paid_amount' => 500, 'accounting_status' => 'reversed'], 5, 500);

        // Opening balance sale: 600
        $this->createSaleWithItem(['grand_total' => 600, 'paid_amount' => 600, 'sale_type' => 'Opening balance'], 6, 600);

        $summary = $this->salesReadService->summary(
            $this->adminContext,
            $today,
            $today,
            ['customer_id' => $this->customer->id]
        );

        $this->assertEquals(100.0, $summary['total_sales'], 'Only active sale should be included in total_sales');
        $this->assertEquals(100.0, $summary['paid_amount']);
        $this->assertEquals(1, $summary['total_orders']);
        $this->assertEquals(1, $summary['total_items']);
    }

    public function test_top_products_report_strictly_excludes_non_active_lifecycle_sales(): void
    {
        $today = Carbon::today()->toDateString();

        // Active sale: 5 units
        $this->createSaleWithItem(['grand_total' => 500, 'paid_amount' => 500], 5, 500);

        // Draft sale: 50 units (must be ignored)
        $this->createSaleWithItem(['grand_total' => 5000, 'paid_amount' => 5000, 'sale_status' => 3], 50, 5000);

        // Reversed sale: 40 units (must be ignored)
        $this->createSaleWithItem(['grand_total' => 4000, 'paid_amount' => 4000, 'accounting_status' => 'reversed'], 40, 4000);

        // Voided sale: 30 units (must be ignored)
        $this->createSaleWithItem(['grand_total' => 3000, 'paid_amount' => 3000, 'voided_at' => Carbon::now()], 30, 3000);

        $report = $this->salesReadService->topProductsReport(
            $this->adminContext,
            $today,
            10
        );

        $productRow = collect($report)->first(fn($r) => str_contains($r['product'], $this->product->code));

        $this->assertNotNull($productRow, 'Product should appear in report from active sale');
        $this->assertEquals(5.0, $productRow['qty_sold'], 'Only active sale quantity should be counted');
        $this->assertEquals(500.0, $productRow['sales_value'], 'Only active sale value should be counted');
    }

    public function test_recent_sales_excludes_draft_and_voided(): void
    {
        // Active sale
        $this->createSaleWithItem(['reference_no' => 'ACTIVE-1', 'grand_total' => 150]);

        // Draft sale
        $this->createSaleWithItem(['reference_no' => 'DRAFT-1', 'grand_total' => 250, 'sale_status' => 3]);

        // Voided sale
        $this->createSaleWithItem(['reference_no' => 'VOID-1', 'grand_total' => 350, 'voided_at' => Carbon::now()]);

        $recent = $this->salesReadService->recentSales($this->adminContext, 50, ['customer_id' => $this->customer->id]);

        $refNos = collect($recent)->pluck('reference_no')->all();

        $this->assertContains('ACTIVE-1', $refNos);
        $this->assertNotContains('DRAFT-1', $refNos);
        $this->assertNotContains('VOID-1', $refNos);
    }
}
