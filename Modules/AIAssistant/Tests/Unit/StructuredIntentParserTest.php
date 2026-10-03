<?php

namespace Modules\AIAssistant\Tests\Unit;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\StructuredIntentParser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StructuredIntentParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['id' => 1], ['name' => 'Admin', 'guard_name' => 'web', 'is_active' => true]);
        Role::firstOrCreate(['id' => 2], ['name' => 'Owner', 'guard_name' => 'web', 'is_active' => true]);
        Permission::firstOrCreate(['name' => 'customers-index', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'suppliers-index', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sales-index', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ai-assistant-index', 'guard_name' => 'web']);
    }

    private function createTestUser(array $permissions = [], ?int $warehouseId = null): User
    {
        $role = Role::firstOrCreate(
            ['name' => 'TestStaff_' . uniqid(), 'guard_name' => 'web'],
            ['is_active' => true]
        );
        if (!empty($permissions)) {
            $role->syncPermissions($permissions);
        }

        $user = User::create([
            'name' => 'Test Staff',
            'email' => 'staff_' . uniqid() . '@example.com',
            'phone' => '123456789',
            'password' => bcrypt('secret123'),
            'role_id' => $role->id,
            'is_active' => true,
            'is_deleted' => false,
            'warehouse_id' => $warehouseId,
        ]);

        $user->assignRole($role);
        return $user;
    }

    public function test_parses_limit_and_resolves_top_products_skill(): void
    {
        $parser = new StructuredIntentParser();
        $result = $parser->parse('top 5 selling products');

        $this->assertSame(5, $result['parameters']['limit'] ?? null);
        $this->assertSame('top_products', $result['skill']);
        $this->assertTrue($result['is_authorized']);
        $this->assertFalse($result['clarification_needed']);
    }

    public function test_parses_relative_date_ranges(): void
    {
        $parser = new StructuredIntentParser();

        $yesterdayResult = $parser->parse('yesterday sales');
        $this->assertSame('yesterday', $yesterdayResult['parameters']['date_range']['period'] ?? null);
        $this->assertSame(Carbon::yesterday()->toDateString(), $yesterdayResult['parameters']['date_range']['start_date'] ?? null);
        $this->assertSame('sales_summary', $yesterdayResult['skill']);

        $thisMonthResult = $parser->parse('expenses this month');
        $this->assertSame('this_month', $thisMonthResult['parameters']['date_range']['period'] ?? null);
        $this->assertSame(Carbon::now()->startOfMonth()->toDateString(), $thisMonthResult['parameters']['date_range']['start_date'] ?? null);
        $this->assertSame('expense_summary', $thisMonthResult['skill']);
    }

    public function test_parses_warehouse_and_enforces_restriction(): void
    {
        $wh1 = Warehouse::create(['name' => 'Wh 1', 'address' => '123 First St', 'is_active' => true]);
        $wh2 = Warehouse::create(['name' => 'Wh 2', 'address' => '456 Second St', 'is_active' => true]);

        $restrictedUser = $this->createTestUser([], $wh1->id);
        $context = AssistantAccessContext::fromUser($restrictedUser);

        $parser = new StructuredIntentParser();

        // Querying authorized warehouse
        $allowedResult = $parser->parse("sales in warehouse {$wh1->id}", $context);
        $this->assertTrue($allowedResult['is_authorized']);
        $this->assertSame($wh1->id, $allowedResult['parameters']['warehouse_id'] ?? null);

        // Querying unauthorized warehouse fails closed
        $deniedResult = $parser->parse("sales in warehouse {$wh2->id}", $context);
        $this->assertFalse($deniedResult['is_authorized']);
        $this->assertStringContainsString((string) $wh2->id, $deniedResult['unauthorized_message']);
    }

    public function test_resolves_unique_customer_entity(): void
    {
        $user = $this->createTestUser(['customers-index']);
        $context = AssistantAccessContext::fromUser($user);

        $customer = Customer::create([
            'customer_group_id' => 1,
            'name' => 'Acme Corporation',
            'phone_number' => '1234567890',
            'is_active' => true,
        ]);

        $parser = new StructuredIntentParser();
        $result = $parser->parse('customer Acme Corporation due', $context);

        $this->assertTrue($result['is_authorized']);
        $this->assertFalse($result['clarification_needed']);
        $this->assertSame('customer', $result['parameters']['entity_type'] ?? null);
        $this->assertSame($customer->id, $result['parameters']['entity_id'] ?? null);
        $this->assertSame('Acme Corporation', $result['parameters']['entity_name'] ?? null);
        $this->assertSame('customer_due', $result['skill']);
    }

    public function test_requires_clarification_on_ambiguous_customer_matches(): void
    {
        $user = $this->createTestUser(['customers-index']);
        $context = AssistantAccessContext::fromUser($user);

        Customer::create(['customer_group_id' => 1, 'name' => 'Alpha Logistics 1', 'phone_number' => '111', 'is_active' => true]);
        Customer::create(['customer_group_id' => 1, 'name' => 'Alpha Logistics 2', 'phone_number' => '222', 'is_active' => true]);

        $parser = new StructuredIntentParser();
        $result = $parser->parse('customer Alpha Logistics due', $context);

        $this->assertTrue($result['is_authorized']);
        $this->assertTrue($result['clarification_needed']);
        $this->assertCount(2, $result['clarification_choices']);
        $this->assertStringContainsString('Multiple customers matched', $result['clarification_message']);
    }

    public function test_fails_closed_when_user_lacks_permission_for_customer_query(): void
    {
        $user = $this->createTestUser([]); // No customers-index permission
        $context = AssistantAccessContext::fromUser($user);

        Customer::create(['customer_group_id' => 1, 'name' => 'Private Client', 'phone_number' => '999', 'is_active' => true]);

        $parser = new StructuredIntentParser();
        $result = $parser->parse('customer Private Client due', $context);

        $this->assertFalse($result['is_authorized']);
        $this->assertStringContainsString('permission', $result['unauthorized_message']);
    }

    public function test_resolves_unique_supplier_entity_and_checks_permission(): void
    {
        $user = $this->createTestUser(['suppliers-index']);
        $context = AssistantAccessContext::fromUser($user);

        $supplier = Supplier::create([
            'name' => 'Delta Global',
            'company_name' => 'Delta Global LLC',
            'email' => 'supplier@delta.com',
            'phone_number' => '5551234',
            'is_active' => true,
            'address' => 'Delta Ave',
            'city' => 'Metropolis',
        ]);

        $parser = new StructuredIntentParser();
        $result = $parser->parse('supplier Delta Global due', $context);

        $this->assertTrue($result['is_authorized']);
        $this->assertFalse($result['clarification_needed']);
        $this->assertSame('supplier', $result['parameters']['entity_type'] ?? null);
        $this->assertSame($supplier->id, $result['parameters']['entity_id'] ?? null);
        $this->assertSame('supplier_due', $result['skill']);
    }

    public function test_execution_service_returns_clarification_on_ambiguous_customer(): void
    {
        $user = $this->createTestUser(['customers-index', 'ai-assistant-index']);

        Customer::create(['customer_group_id' => 1, 'name' => 'Beta Express A', 'phone_number' => '111', 'is_active' => true]);
        Customer::create(['customer_group_id' => 1, 'name' => 'Beta Express B', 'phone_number' => '222', 'is_active' => true]);

        /** @var \Modules\AIAssistant\Services\AssistantExecutionService $executionService */
        $executionService = app(\Modules\AIAssistant\Services\AssistantExecutionService::class);
        $response = $executionService->execute('customer Beta Express due', $user);

        $this->assertSame('clarification_required', $response->metadata['status'] ?? null);
        $this->assertNotEmpty($response->metadata['clarification_choices'] ?? []);
    }

    public function test_execution_service_fails_closed_on_unauthorized_warehouse_prompt(): void
    {
        $wh1 = Warehouse::create(['name' => 'Wh 1', 'address' => '123 First St', 'is_active' => true]);
        $wh2 = Warehouse::create(['name' => 'Wh 2', 'address' => '456 Second St', 'is_active' => true]);

        $restrictedUser = $this->createTestUser(['sales-index', 'ai-assistant-index'], $wh1->id);

        /** @var \Modules\AIAssistant\Services\AssistantExecutionService $executionService */
        $executionService = app(\Modules\AIAssistant\Services\AssistantExecutionService::class);
        $response = $executionService->execute("sales in warehouse {$wh2->id}", $restrictedUser);

        $this->assertSame('error', $response->responseType);
        $this->assertSame('permission_denied', $response->metadata['status'] ?? null);
        $this->assertTrue($response->metadata['failed_closed'] ?? false);
    }
}
