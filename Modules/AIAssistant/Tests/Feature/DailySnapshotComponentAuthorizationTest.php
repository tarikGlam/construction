<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Skills\DailySnapshotSkill;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DailySnapshotComponentAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement("SET SESSION sql_mode=''");

        $this->warehouse = Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => 1],
            ['name' => 'Main Warehouse', 'address' => 'Test Address', 'is_active' => true]
        );

        // Seed sample data for today
        Sale::create([
            'reference_no' => 'S-SNAP-1',
            'user_id' => 1,
            'customer_id' => 1,
            'warehouse_id' => $this->warehouse->id,
            'biller_id' => 1,
            'item' => 1,
            'total_qty' => 1,
            'total_price' => 100,
            'grand_total' => 100,
            'paid_amount' => 60,
            'payment_status' => 2,
            'sale_status' => 1,
            'created_at' => Carbon::today(),
            'updated_at' => Carbon::today()
        ]);

        Purchase::create([
            'reference_no' => 'P-SNAP-1',
            'user_id' => 1,
            'supplier_id' => 1,
            'warehouse_id' => $this->warehouse->id,
            'item' => 1,
            'total_qty' => 2,
            'total_cost' => 200,
            'grand_total' => 200,
            'paid_amount' => 50,
            'status' => 1,
            'payment_status' => 2,
            'created_at' => Carbon::today(),
            'updated_at' => Carbon::today()
        ]);

        $cat = ExpenseCategory::firstOrCreate(['code' => 'EC-SNAP'], ['name' => 'Office', 'is_active' => true]);
        Expense::create([
            'reference_no' => 'E-SNAP-1',
            'expense_category_id' => $cat->id,
            'warehouse_id' => $this->warehouse->id,
            'account_id' => 1,
            'user_id' => 1,
            'amount' => 75,
            'created_at' => Carbon::today()
        ]);

        $product = Product::firstOrCreate(
            ['code' => 'PROD-SNAP-1'],
            ['name' => 'Snap Product', 'type' => 'standard', 'barcode_symbology' => 'code128', 'unit_id' => 1, 'purchase_unit_id' => 1, 'sale_unit_id' => 1, 'cost' => 10, 'price' => 20, 'qty' => 2, 'alert_quantity' => 10, 'is_active' => true]
        );
        Product_Warehouse::firstOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 2]
        );
    }

    private function createStaffUserWithPermissions(array $permissionNames): User
    {
        $role = Role::create([
            'name' => 'Role_' . uniqid(),
            'guard_name' => 'web',
            'is_active' => true,
        ]);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->givePermissionTo($permission);
        }

        $user = new User();
        $user->name = 'Staff_' . uniqid();
        $user->email = 'staff_' . uniqid() . '@example.test';
        $user->password = bcrypt('secret');
        $user->phone = '5551234';
        $user->role_id = $role->id;
        $user->warehouse_id = $this->warehouse->id;
        $user->is_active = true;
        $user->is_deleted = false;
        $user->save();

        return $user;
    }

    private function executeSnapshot(User $user)
    {
        $skill = new DailySnapshotSkill();
        $message = new AssistantMessageData(role: 'user', content: 'daily snapshot');
        $accessContext = AssistantAccessContext::fromUser($user);
        $context = new AssistantContextData(
            userId: $user->id,
            accessContext: $accessContext
        );

        return $skill->handle($message, $context);
    }

    public function test_user_with_only_sales_permission(): void
    {
        $user = $this->createStaffUserWithPermissions(['sales-index']);
        $response = $this->executeSnapshot($user);

        $this->assertEquals('card', $response->responseType);

        // Sales is permitted
        $this->assertEquals(100.0, collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_sales'))['value']);
        $this->assertEquals(40.0, collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_sales_due_created'))['value']);

        // Purchases, Expenses, Low Stock are marked Unavailable
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_purchases'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_purchases_due_created'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_expenses'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_low_stock_items'))['value']);

        // Only sales link is included
        $this->assertCount(1, $response->links);
        $this->assertStringContainsString('/sales', $response->links[0]['url']);

        // Permissions in metadata
        $this->assertTrue($response->metadata['component_permissions']['sales']);
        $this->assertFalse($response->metadata['component_permissions']['purchases']);
        $this->assertFalse($response->metadata['component_permissions']['expenses']);
        $this->assertFalse($response->metadata['component_permissions']['inventory']);
    }

    public function test_user_with_only_purchases_permission(): void
    {
        $user = $this->createStaffUserWithPermissions(['purchases-index']);
        $response = $this->executeSnapshot($user);

        $this->assertEquals('card', $response->responseType);

        // Purchases is permitted
        $this->assertEquals(200.0, collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_purchases'))['value']);
        $this->assertEquals(150.0, collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_purchases_due_created'))['value']);

        // Sales, Expenses, Low Stock are marked Unavailable
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_sales'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_sales_due_created'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_expenses'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_low_stock_items'))['value']);

        // Only purchase link is included
        $this->assertCount(1, $response->links);
        $this->assertStringContainsString('/purchases', $response->links[0]['url']);

        $this->assertFalse($response->metadata['component_permissions']['sales']);
        $this->assertTrue($response->metadata['component_permissions']['purchases']);
        $this->assertFalse($response->metadata['component_permissions']['expenses']);
        $this->assertFalse($response->metadata['component_permissions']['inventory']);
    }

    public function test_user_with_only_inventory_permission(): void
    {
        $user = $this->createStaffUserWithPermissions(['products-index']);
        $response = $this->executeSnapshot($user);

        $this->assertEquals('card', $response->responseType);

        // Low stock is permitted
        $lowStockCard = collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_low_stock_items'));
        $this->assertNotNull($lowStockCard);
        $this->assertIsNumeric($lowStockCard['value']);

        // Sales, Purchases, Expenses are marked Unavailable
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_sales'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_purchases'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_expenses'))['value']);

        // No business transaction links
        $this->assertEmpty($response->links);

        $this->assertFalse($response->metadata['component_permissions']['sales']);
        $this->assertFalse($response->metadata['component_permissions']['purchases']);
        $this->assertFalse($response->metadata['component_permissions']['expenses']);
        $this->assertTrue($response->metadata['component_permissions']['inventory']);
    }

    public function test_assistant_only_user_with_no_business_permissions(): void
    {
        $user = $this->createStaffUserWithPermissions([]);
        $response = $this->executeSnapshot($user);

        $this->assertEquals('card', $response->responseType);

        // Every business component is marked Unavailable
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_sales'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_sales_due_created'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_purchases'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_purchases_due_created'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_expenses'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_total_transactions'))['value']);
        $this->assertEquals('Unavailable', collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_low_stock_items'))['value']);

        $this->assertEmpty($response->links);

        $this->assertFalse($response->metadata['component_permissions']['sales']);
        $this->assertFalse($response->metadata['component_permissions']['purchases']);
        $this->assertFalse($response->metadata['component_permissions']['expenses']);
        $this->assertFalse($response->metadata['component_permissions']['inventory']);
    }

    public function test_user_with_all_permissions_receives_complete_snapshot(): void
    {
        $user = $this->createStaffUserWithPermissions([
            'sales-index',
            'purchases-index',
            'expenses-index',
            'products-index',
            'account-index',
        ]);
        $response = $this->executeSnapshot($user);

        $this->assertEquals('card', $response->responseType);

        // All cards have numerical values
        $this->assertIsNumeric(collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_sales'))['value']);
        $this->assertIsNumeric(collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_sales_due_created'))['value']);
        $this->assertIsNumeric(collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_purchases'))['value']);
        $this->assertIsNumeric(collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_purchases_due_created'))['value']);
        $this->assertIsNumeric(collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_todays_expenses'))['value']);
        $this->assertIsNumeric(collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_total_transactions'))['value']);
        $this->assertIsNumeric(collect($response->cards)->firstWhere('title', __('db.ai_assistant_card_low_stock_items'))['value']);

        // All links present
        $this->assertCount(3, $response->links);

        $this->assertTrue($response->metadata['component_permissions']['sales']);
        $this->assertTrue($response->metadata['component_permissions']['purchases']);
        $this->assertTrue($response->metadata['component_permissions']['expenses']);
        $this->assertTrue($response->metadata['component_permissions']['inventory']);
    }
}
