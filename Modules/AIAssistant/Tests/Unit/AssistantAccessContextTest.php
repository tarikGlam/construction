<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ModuleAccessService;
use App\Services\WarehouseAccessService;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AssistantAccessContextTest extends TestCase
{
    private function ensureWarehouse(int $id, string $name): Warehouse
    {
        return Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => $id],
            ['name' => $name, 'address' => 'Test Address', 'is_active' => true]
        );
    }

    private function createTestRole(string $prefix): Role
    {
        return Role::create([
            'name' => $prefix . '_' . uniqid(),
            'guard_name' => 'web',
            'is_active' => true,
        ]);
    }

    public function test_null_user_fails_closed(): void
    {
        $context = AssistantAccessContext::fromUser(null);

        $this->assertNull($context->user);
        $this->assertFalse($context->isGlobalWarehouseAccess);
        $this->assertTrue($context->isRestrictedWarehouseAccess);
        $this->assertEmpty($context->allowedWarehouseIds);
        $this->assertFalse($context->hasPermission('sales-index'));
        $this->assertEquals(WarehouseAccessService::INVALID_OPERATIONAL, $context->warehouseClassification);
    }

    public function test_admin_user_has_global_warehouse_access_and_all_permissions(): void
    {
        $admin = new User([
            'id' => 1,
            'role_id' => 1,
            'is_active' => true,
        ]);

        $context = AssistantAccessContext::fromUser($admin);

        $this->assertTrue($context->isGlobalWarehouseAccess);
        $this->assertFalse($context->isRestrictedWarehouseAccess);
        $this->assertEmpty($context->allowedWarehouseIds);
        $this->assertTrue($context->hasPermission('any-permission-at-all'));
        $this->assertEquals(WarehouseAccessService::GLOBAL_OPERATIONAL, $context->warehouseClassification);

        $scope = $context->toWarehouseScope();
        $this->assertFalse($scope->isRestricted);
        $this->assertNull($scope->ownUserId);
    }

    public function test_role_with_all_warehouses_permission_is_global(): void
    {
        $role = $this->createTestRole('GlobalRole');
        $permission = Permission::firstOrCreate(['name' => 'all-warehouses', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = new User([
            'id' => 10,
            'role_id' => $role->id,
            'warehouse_id' => null,
            'is_active' => true,
        ]);

        $context = AssistantAccessContext::fromUser($user);

        $this->assertTrue($context->isGlobalWarehouseAccess);
        $this->assertFalse($context->isRestrictedWarehouseAccess);
        $this->assertEquals(WarehouseAccessService::GLOBAL_OPERATIONAL, $context->warehouseClassification);
    }

    public function test_operational_staff_with_valid_warehouse_is_restricted(): void
    {
        $wh = $this->ensureWarehouse(1, 'Alpha Warehouse');
        $role = $this->createTestRole('StaffRole');

        $user = new User([
            'id' => 20,
            'role_id' => $role->id,
            'warehouse_id' => $wh->id,
            'is_active' => true,
        ]);

        $context = AssistantAccessContext::fromUser($user);

        $this->assertFalse($context->isGlobalWarehouseAccess);
        $this->assertTrue($context->isRestrictedWarehouseAccess);
        $this->assertEquals([$wh->id], $context->allowedWarehouseIds);
        $this->assertEquals(WarehouseAccessService::WAREHOUSE_OPERATIONAL, $context->warehouseClassification);

        $scope = $context->toWarehouseScope();
        $this->assertTrue($scope->isRestricted);
        $this->assertEquals([$wh->id], $scope->warehouseIds);
        $this->assertNull($scope->ownUserId);
    }

    public function test_unassigned_operational_staff_is_invalid_operational_and_fails_closed(): void
    {
        $role = $this->createTestRole('UnassignedRole');

        $user = new User([
            'id' => 30,
            'role_id' => $role->id,
            'warehouse_id' => null,
            'is_active' => true,
        ]);

        $context = AssistantAccessContext::fromUser($user);

        $this->assertFalse($context->isGlobalWarehouseAccess);
        $this->assertTrue($context->isRestrictedWarehouseAccess);
        $this->assertEmpty($context->allowedWarehouseIds);
        $this->assertEquals(WarehouseAccessService::INVALID_OPERATIONAL, $context->warehouseClassification);

        $scope = $context->toWarehouseScope();
        $this->assertTrue($scope->isRestricted);
        $this->assertEmpty($scope->warehouseIds);
    }

    public function test_page_context_preservation(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $cust = \App\Models\Customer::create([
            'customer_group_id' => 1,
            'name' => 'Context Customer ' . uniqid(),
            'phone_number' => '111',
            'is_active' => true,
        ]);
        $pageContext = ['module' => 'sales', 'entity_type' => 'customer', 'entity_id' => $cust->id];

        $context = AssistantAccessContext::fromUser($admin, pageContext: $pageContext);

        $this->assertEquals('sales', $context->pageContext['module']);
        $this->assertEquals('customer', $context->pageContext['entity_type']);
        $this->assertEquals($cust->id, $context->pageContext['entity_id']);
        $businessContext = $context->toBusinessContext();
        $this->assertEquals($context->pageContext, $businessContext['page_context']);
    }

    public function test_module_availability_check(): void
    {
        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        // AIAssistant module is enabled
        $this->assertTrue($context->isModuleAvailable('AIAssistant'));

        // Non-existent module is false
        $this->assertFalse($context->isModuleAvailable('NonExistentModule123'));
    }
}
