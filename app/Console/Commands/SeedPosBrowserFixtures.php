<?php

namespace App\Console\Commands;

use App\Models\Biller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PosSetting;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\ProductVariant;
use App\Models\Table;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use Database\Seeders\Tenant\TenantDatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SeedPosBrowserFixtures extends Command
{
    protected $signature = 'salepro:seed-pos-browser-fixtures';

    protected $description = 'Seed deterministic SalePro POS fixtures for the Playwright certification suite';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        $isApprovedTestDatabase = str_ends_with($database, '_testing')
            || $database === 'salepro_webhook_clean';

        if (!app()->environment('testing') || !$isApprovedTestDatabase) {
            $this->error('POS browser fixtures may only run in an approved disposable database with APP_ENV=testing.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            $this->seedFixtures();
        });

        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Deterministic POS browser fixtures seeded.');

        return self::SUCCESS;
    }

    private function seedFixtures(): void
    {
        DB::table('roles')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Admin', 'guard_name' => 'web', 'is_active' => 1]
        );

        $adminRole = Role::findOrFail(1);
        $adminRole->givePermissionTo((new TenantDatabaseSeeder())->seedCanonicalPermissions());

        $retailA = $this->warehouse('E2E Retail Warehouse A', 'e2e-retail-a@salepro.test', 'regular');
        $retailB = $this->warehouse('E2E Retail Warehouse B', 'e2e-retail-b@salepro.test', 'regular');
        $restaurant = $this->warehouse('E2E Restaurant Warehouse', 'e2e-restaurant@salepro.test', 'restaurant');

        $admin = User::updateOrCreate(
            ['email' => 'pos-browser@salepro.test'],
            [
                'name' => 'POS Browser Admin',
                'phone' => '8801700000001',
                'password' => Hash::make('password'),
                'role_id' => 1,
                'warehouse_id' => null,
                'is_active' => 1,
                'is_deleted' => 0,
            ]
        );
        $admin->syncRoles([$adminRole]);
        $this->deletePriorDrafts($admin->id);

        $waiter = User::updateOrCreate(
            ['email' => 'pos-browser-waiter@salepro.test'],
            [
                'name' => 'E2E Waiter',
                'phone' => '8801700000002',
                'password' => Hash::make('password'),
                'role_id' => 1,
                'warehouse_id' => $restaurant->id,
                'service_staff' => 1,
                'is_active' => 1,
                'is_deleted' => 0,
            ]
        );
        $waiter->syncRoles([$adminRole]);

        $customerGroup = CustomerGroup::updateOrCreate(
            ['name' => 'E2E Customer Group'],
            ['percentage' => 0, 'is_active' => 1]
        );
        $customer = Customer::updateOrCreate(
            ['email' => 'pos-browser-customer@salepro.test'],
            [
                'customer_group_id' => $customerGroup->id,
                'name' => 'E2E Walk-in Customer',
                'phone_number' => '8801700000003',
                'address' => 'E2E Address',
                'city' => 'E2E City',
                'is_active' => 1,
            ]
        );
        $biller = Biller::updateOrCreate(
            ['email' => 'pos-browser-biller@salepro.test'],
            [
                'name' => 'E2E Biller',
                'company_name' => 'SalePro E2E',
                'phone_number' => '8801700000004',
                'address' => 'E2E Address',
                'city' => 'E2E City',
                'is_active' => 1,
            ]
        );
        $currency = Currency::updateOrCreate(
            ['code' => 'E2E'],
            ['name' => 'E2E Dollar', 'symbol' => '$', 'exchange_rate' => 1, 'is_active' => 1]
        );
        $unit = Unit::updateOrCreate(
            ['unit_code' => 'e2e-pc'],
            ['unit_name' => 'E2E Piece', 'operator' => '*', 'operation_value' => 1, 'is_active' => 1]
        );

        $staffRole = Role::firstOrCreate(['id' => 4], ['name' => 'Staff', 'guard_name' => 'web', 'is_active' => 1]);
        $staffRole->syncPermissions([
            'sales-index',
            'sale-payment-add',
            'pending_collections-create',
            'pending_collections-handover',
        ]);

        $staff = User::updateOrCreate(
            ['email' => 'pos-browser-staff@salepro.test'],
            [
                'name' => 'POS Browser Staff',
                'phone' => '8801700000005',
                'password' => Hash::make('password'),
                'role_id' => 4,
                'warehouse_id' => $retailA->id,
                'is_active' => 1,
                'is_deleted' => 0,
            ]
        );
        $staff->syncRoles([$staffRole]);

        $account = \App\Models\Account::updateOrCreate(
            ['account_no' => 'E2E-ACC-01'],
            [
                'name' => 'E2E Main Cash',
                'initial_balance' => 1000,
                'total_balance' => 1000,
                'is_default' => 1,
                'is_active' => 1,
            ]
        );

        $dueSale = \App\Models\Sale::updateOrCreate(
            ['reference_no' => 'pos-e2e-due-sale'],
            [
                'user_id' => $admin->id,
                'customer_id' => $customer->id,
                'warehouse_id' => $retailA->id,
                'biller_id' => $biller->id,
                'item' => 1,
                'total_qty' => 1,
                'total_discount' => 0,
                'total_tax' => 0,
                'total_price' => 100,
                'grand_total' => 100,
                'paid_amount' => 0,
                'payment_status' => 2,
                'sale_status' => 1,
                'currency_id' => $currency->id,
                'exchange_rate' => 1,
            ]
        );

        DB::table('general_settings')->updateOrInsert(
            ['id' => 1],
            [
                'site_title' => 'SalePro POS E2E',
                'site_logo' => 'sale pro logo 183x50 pix-01.png',
                'favicon' => 'sale pro logo 183x50 pix-01.png',
                'is_rtl' => 0,
                'currency' => $currency->id,
                'currency_position' => 'prefix',
                'staff_access' => 'all',
                'without_stock' => 'no',
                'date_format' => 'd-m-Y',
                'theme' => 'default.css',
                'modules' => 'restaurant',
                'margin_type' => 0,
                'disable_forgot_password' => 0,
            ]
        );

        PosSetting::updateOrCreate(
            ['id' => 1],
            [
                'customer_id' => $customer->id,
                'warehouse_id' => $retailA->id,
                'biller_id' => $biller->id,
                'product_number' => 20,
                'payment_options' => 'cash,card',
                'keybord_active' => 0,
                'is_table' => 1,
                'play_sound' => 0,
            ]
        );

        DB::table('invoice_settings')->where('status', 1)->update(['status' => 0]);
        DB::table('invoice_settings')->updateOrInsert(
            ['template_name' => 'E2E Standard A4'],
            [
                'size' => 'a4',
                'status' => 1,
                'is_default' => 1,
                'invoice_date_format' => 'd-m-Y',
                'show_column' => json_encode([
                    'show_warehouse_info' => 1,
                    'show_bill_to_info' => 1,
                    'show_description' => 1,
                    'show_ref_number' => 1,
                    'show_customer_name' => 1,
                ]),
                'created_by' => $admin->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $apple = Brand::updateOrCreate(
            ['title' => 'E2E Apple'],
            ['image' => '../zummXD2dvAtI.png', 'is_active' => 1]
        );
        $otherBrand = Brand::updateOrCreate(
            ['title' => 'E2E Acme'],
            ['image' => '../zummXD2dvAtI.png', 'is_active' => 1]
        );
        $phones = Category::updateOrCreate(
            ['name' => 'E2E Phones'],
            ['slug' => 'e2e-phones', 'image' => '../zummXD2dvAtI.png', 'is_active' => 1]
        );
        $general = Category::updateOrCreate(
            ['name' => 'E2E General'],
            ['slug' => 'e2e-general', 'image' => '../zummXD2dvAtI.png', 'is_active' => 1]
        );
        $restaurantCategory = Category::updateOrCreate(
            ['name' => 'E2E Restaurant Menu'],
            ['slug' => 'e2e-restaurant-menu', 'image' => '../zummXD2dvAtI.png', 'is_active' => 1]
        );

        $products = [
            'normal' => $this->product('E2E-NORMAL-A', 'E2E Normal Product', $general, $otherBrand, $unit, false),
            'featured' => $this->product('E2E-FEATURED-A', 'E2E Featured Product', $general, $otherBrand, $unit, true),
            'iphone' => $this->product('E2E-IPHONE-A', 'E2E Apple iPhone 16 128GB', $phones, $apple, $unit, true),
            'other' => $this->product('E2E-OTHER-A', 'E2E Acme Tablet', $phones, $otherBrand, $unit, false),
            'variant' => $this->product('E2E-VARIANT-A', 'E2E Variant Phone', $phones, $apple, $unit, false),
            'imei' => $this->product('E2E-IMEI-A', 'E2E IMEI Phone', $phones, $apple, $unit, false),
            'retail_b' => $this->product('E2E-B-ONLY', 'E2E Warehouse B Product', $general, $otherBrand, $unit, true),
            'restaurant' => $this->product('E2E-R-ITEM', 'E2E Restaurant Meal', $restaurantCategory, $otherBrand, $unit, true, 'Main'),
        ];

        $products['variant']->update(['is_variant' => 1]);
        $midnight = Variant::updateOrCreate(['name' => 'E2E Midnight'], []);
        ProductVariant::updateOrCreate(
            ['product_id' => $products['variant']->id, 'variant_id' => $midnight->id],
            ['position' => 1, 'item_code' => 'E2E-VAR-MID', 'additional_price' => 2, 'qty' => 2]
        );
        $products['imei']->update(['is_imei' => 1]);

        Product_Warehouse::whereIn('product_id', collect($products)->pluck('id'))->delete();
        $this->stock($products['normal'], $retailA, 3);
        $this->stock($products['featured'], $retailA, 5);
        $this->stock($products['iphone'], $retailA, 4);
        $this->stock($products['other'], $retailA, 7);
        $this->stock($products['variant'], $retailA, 2, $midnight->id);
        $this->stock($products['imei'], $retailA, 1, null, '867530900000001');
        $this->stock($products['retail_b'], $retailB, 6);
        $this->stock($products['restaurant'], $restaurant, 8);

        DB::table('services')->updateOrInsert(['id' => 1], ['name' => 'Dine In', 'is_active' => 1]);
        DB::table('services')->updateOrInsert(['id' => 2], ['name' => 'Take Away', 'is_active' => 1]);

        $floorId = DB::table('floors')->where('name', 'E2E Dining Floor')->value('id');
        if ($floorId) {
            DB::table('floors')->where('id', $floorId)->update([
                'warehouse_id' => $restaurant->id,
                'updated_at' => now(),
            ]);
        } else {
            $floorId = DB::table('floors')->insertGetId([
                'name' => 'E2E Dining Floor',
                'warehouse_id' => $restaurant->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Table::updateOrCreate(
            ['name' => 'E2E Table 1', 'floor_id' => $floorId],
            ['number_of_person' => 4, 'is_active' => 1]
        );

        Cache::put('currency_list', Currency::where('is_active', 1)->get());
        Cache::put('categories_list', Category::where('is_active', 1)->get());
    }

    private function warehouse(string $name, string $email, string $posType): Warehouse
    {
        return Warehouse::updateOrCreate(
            ['name' => $name],
            [
                'phone' => '8801700000099',
                'email' => $email,
                'address' => 'E2E Address',
                'pos_type' => $posType,
                'is_active' => 1,
            ]
        );
    }

    private function product(
        string $code,
        string $name,
        Category $category,
        Brand $brand,
        Unit $unit,
        bool $featured,
        ?string $menuType = null
    ): Product {
        return Product::updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'type' => 'standard',
                'barcode_symbology' => 'C128',
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'unit_id' => $unit->id,
                'purchase_unit_id' => $unit->id,
                'sale_unit_id' => $unit->id,
                'cost' => 5,
                'price' => 10,
                'qty' => 0,
                'alert_quantity' => 1,
                'featured' => $featured,
                'menu_type' => $menuType,
                'is_active' => 1,
                'image' => '../zummXD2dvAtI.png',
            ]
        );
    }

    private function stock(
        Product $product,
        Warehouse $warehouse,
        float $quantity,
        ?int $variantId = null,
        ?string $imeiNumber = null
    ): void
    {
        Product_Warehouse::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'variant_id' => $variantId,
            'imei_number' => $imeiNumber,
            'qty' => $quantity,
            'price' => $product->price,
        ]);
    }

    private function deletePriorDrafts(int $userId): void
    {
        $draftIds = DB::table('sales')
            ->where('user_id', $userId)
            ->where('sale_status', 3)
            ->pluck('id');

        if ($draftIds->isEmpty()) {
            return;
        }

        foreach (['product_sales', 'payments', 'deliveries'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('sale_id', $draftIds)->delete();
            }
        }

        DB::table('sales')->whereIn('id', $draftIds)->delete();
    }
}
