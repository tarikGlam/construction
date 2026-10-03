<?php

namespace Modules\AIAssistant\Tests\Unit;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\PageContextResolver;
use Tests\TestCase;

class PageContextResolverTest extends TestCase
{
    use DatabaseTransactions;

    private PageContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PageContextResolver();
    }

    public function test_empty_raw_context_returns_empty(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1]);
        $resolved = $this->resolver->resolve($admin, false, [], []);
        $this->assertEmpty($resolved);
    }

    public function test_page_is_sanitized(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1]);
        $resolved = $this->resolver->resolve($admin, false, [], [
            'page' => '<script>alert(1)</script>sales.index'
        ]);

        $this->assertEquals('scriptalert1scriptsales.index', $resolved['page']);
    }

    public function test_warehouse_spoofing_is_rejected_for_restricted_user(): void
    {
        $user = new User(['id' => 5, 'role_id' => 3]);
        // User is restricted to warehouse 1
        $resolved = $this->resolver->resolve($user, true, [1], [
            'page' => 'sales.index',
            'warehouse_id' => 2, // Trying to spoof warehouse 2!
        ]);

        $this->assertArrayNotHasKey('warehouse_id', $resolved);

        // When requesting allowed warehouse 1, it is accepted
        $validResolved = $this->resolver->resolve($user, true, [1], [
            'page' => 'sales.index',
            'warehouse_id' => 1,
        ]);
        $this->assertEquals(1, $validResolved['warehouse_id']);
    }

    public function test_non_existent_entity_is_discarded(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1]);
        $resolved = $this->resolver->resolve($admin, false, [], [
            'entity_type' => 'customer',
            'entity_id' => 99999999, // Does not exist
        ]);

        $this->assertArrayNotHasKey('entity_type', $resolved);
        $this->assertArrayNotHasKey('entity_id', $resolved);
    }

    public function test_unpermitted_entity_type_is_rejected(): void
    {
        // Staff user without permissions
        $staff = User::create([
            'name' => 'Staff Without Customer Perm',
            'email' => 'staff_no_cust_' . uniqid() . '@example.com',
            'phone' => '123456789',
            'password' => bcrypt('password'),
            'role_id' => 4,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $customer = Customer::create([
            'customer_group_id' => 1,
            'name' => 'Private Customer',
            'phone_number' => '555',
            'is_active' => true,
        ]);

        $resolved = $this->resolver->resolve($staff, false, [], [
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
        ]);

        $this->assertArrayNotHasKey('entity_type', $resolved);
        $this->assertArrayNotHasKey('entity_id', $resolved);
    }

    public function test_valid_customer_entity_is_loaded_and_normalized(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_ctx_test@example.com'],
            ['name' => 'Admin Test', 'phone' => '123456789', 'password' => bcrypt('password'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false]
        );

        $customer = Customer::create([
            'customer_group_id' => 1,
            'name' => 'Acme Corporation',
            'phone_number' => '555-1234',
            'is_active' => true,
        ]);

        $resolved = $this->resolver->resolve($admin, false, [], [
            'module' => 'sales',
            'page' => 'customers.index',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
        ]);

        $this->assertEquals('sales', $resolved['module']);
        $this->assertEquals('customers.index', $resolved['page']);
        $this->assertEquals('customer', $resolved['entity_type']);
        $this->assertEquals($customer->id, $resolved['entity_id']);
        $this->assertEquals('Acme Corporation', $resolved['entity_name']);
    }

    public function test_valid_product_entity_is_loaded_and_normalized(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin_ctx_prod@example.com'],
            ['name' => 'Admin Test', 'phone' => '123456789', 'password' => bcrypt('password'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false]
        );

        $product = Product::create([
            'category_id' => 1,
            'name' => 'Context Test Widget',
            'code' => 'CTW-99',
            'type' => 'standard',
            'barcode_symbology' => 'code128',
            'unit_id' => 1,
            'purchase_unit_id' => 1,
            'sale_unit_id' => 1,
            'cost' => 10,
            'price' => 20,
            'qty' => 50,
            'is_active' => true,
        ]);

        $resolved = $this->resolver->resolve($admin, false, [], [
            'page' => 'products.index',
            'entity_type' => 'product',
            'entity_id' => $product->id,
        ]);

        $this->assertEquals('product', $resolved['entity_type']);
        $this->assertEquals($product->id, $resolved['entity_id']);
        $this->assertEquals('Context Test Widget', $resolved['entity_name']);
    }

    public function test_arbitrary_module_is_rejected(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1]);
        $resolved = $this->resolver->resolve($admin, false, [], [
            'module' => 'unsupported_malicious_module_xyz',
            'page' => 'dashboard'
        ]);

        $this->assertArrayNotHasKey('module', $resolved);
        $this->assertEquals('dashboard', $resolved['page']);
    }

    public function test_client_supplied_tenant_identity_is_ignored(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1]);
        $resolved = $this->resolver->resolve($admin, false, [], [
            'tenant_id' => 'forged_tenant_123',
            'tenant' => 'attacker_tenant',
            'page' => 'sales.index'
        ]);

        $this->assertArrayNotHasKey('tenant_id', $resolved);
        $this->assertArrayNotHasKey('tenant', $resolved);
        $this->assertEquals('sales.index', $resolved['page']);
    }

    public function test_inactive_customer_is_rejected(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1]);
        $customer = Customer::create([
            'customer_group_id' => 1,
            'name' => 'Inactive Customer',
            'phone_number' => '555',
            'is_active' => false,
        ]);

        $resolved = $this->resolver->resolve($admin, false, [], [
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
        ]);

        $this->assertArrayNotHasKey('entity_type', $resolved);
        $this->assertArrayNotHasKey('entity_id', $resolved);
    }
}
