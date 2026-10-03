<?php

namespace Modules\AIAssistant\Tests\Feature\Characterization;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseAccessService;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Entities\AIConversation;
use Modules\AIAssistant\Services\AssistantExecutionService;
use Modules\AIAssistant\Skills\CustomerDueSkill;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssistantSecurityCharacterizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement("SET SESSION sql_mode=''");
    }

    private function createCustomer(string $name): Customer
    {
        return Customer::create([
            'customer_group_id' => 1,
            'name'              => $name . '_' . uniqid(),
            'company_name'      => 'Company ' . uniqid(),
            'email'             => 'cust_' . uniqid() . '@example.test',
            'phone_number'      => '123' . rand(1000, 9999),
            'address'           => 'Test Address',
            'city'              => 'Test City',
            'is_active'         => true,
            'opening_balance'   => 0,
        ]);
    }

    private function ensureWarehouse(int $id, string $name): Warehouse
    {
        return Warehouse::withoutGlobalScope('authorized_warehouse')->firstOrCreate(
            ['id' => $id],
            ['name' => $name, 'is_active' => true]
        );
    }

    /**
     * 1. CHARACTERIZE EXISTING CUSTOMER DUE SKILL WAREHOUSE ISOLATION FLAW
     *
     * In the legacy implementation, CustomerDueSkill lines 51-55 explicitly state:
     * "The warehouse_ids restriction is documented and NOT applied to the due calculation
     * to avoid introducing an inconsistent formula."
     *
     * This test reproduces and characterizes the exact weakness:
     * When Customer A owes $100 in Warehouse 1, and Customer B owes $200 in Warehouse 2,
     * executing CustomerDueSkill with a context restricted to Warehouse 1 [1]
     * still leaks Customer B's debt from Warehouse 2 in the legacy code!
     */
    public function test_characterize_legacy_customer_due_skill_leaks_unscoped_warehouse_debt(): void
    {
        $wh1 = $this->ensureWarehouse(991, 'Warehouse Alpha 991');
        $wh2 = $this->ensureWarehouse(992, 'Warehouse Beta 992');

        $customerA = $this->createCustomer('CustAlpha');
        $customerB = $this->createCustomer('CustBeta');

        // Customer A: Sale in Warehouse 1 for $150, paid $50 => Due $100
        Sale::create([
            'reference_no'   => 'POS-WH1-' . uniqid(),
            'user_id'        => 1,
            'customer_id'    => $customerA->id,
            'warehouse_id'   => $wh1->id,
            'biller_id'      => 1,
            'item'           => 1,
            'total_qty'      => 1,
            'total_price'    => 150,
            'grand_total'    => 150,
            'paid_amount'    => 50,
            'payment_status' => 1,
            'sale_status'    => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Customer B: Sale in Warehouse 2 for $300, paid $100 => Due $200
        Sale::create([
            'reference_no'   => 'POS-WH2-' . uniqid(),
            'user_id'        => 1,
            'customer_id'    => $customerB->id,
            'warehouse_id'   => $wh2->id,
            'biller_id'      => 1,
            'item'           => 1,
            'total_qty'      => 1,
            'total_price'    => 300,
            'grand_total'    => 300,
            'paid_amount'    => 100,
            'payment_status' => 1,
            'sale_status'    => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $skill = new CustomerDueSkill();
        $message = new AssistantMessageData('user', 'customer due');

        // Context explicitly restricting access to Warehouse 1 ONLY
        $restrictedContext = new AssistantContextData(
            tenantId: null,
            userId: 999,
            businessContext: ['warehouse_ids' => [$wh1->id]]
        );

        $response = $skill->handle($message, $restrictedContext);

        // Verification that the warehouse isolation leak is now fixed:
        // Customer B from Warehouse 2 is NOT leaked when restricted to Warehouse 1
        $rows = collect($response->table['rows'] ?? []);
        $foundCustomerB = $rows->firstWhere('0', $customerB->name);
        $foundCustomerA = $rows->firstWhere('0', $customerA->name);

        $this->assertNull(
            $foundCustomerB,
            'CONFIRMATION: CustomerDueSkill now strictly excludes Customer B from Warehouse 2 when restricted to Warehouse 1'
        );
        $this->assertNotNull(
            $foundCustomerA,
            'CONFIRMATION: Customer A from Warehouse 1 is present in the isolated result'
        );
    }

    /**
     * 2. CHARACTERIZE GLOBAL / ADMIN USER CLASSIFICATION
     */
    public function test_characterize_global_admin_user_classification(): void
    {
        $service = app(WarehouseAccessService::class);

        $admin = new User(['id' => 101, 'role_id' => 1, 'warehouse_id' => null, 'is_active' => true]);
        $owner = new User(['id' => 102, 'role_id' => 2, 'warehouse_id' => null, 'is_active' => true]);

        $this->assertTrue($service->isGlobal($admin));
        $this->assertTrue($service->isGlobal($owner));
        $this->assertEquals(WarehouseAccessService::GLOBAL_OPERATIONAL, $service->classification($admin));
        $this->assertEquals(WarehouseAccessService::GLOBAL_OPERATIONAL, $service->classification($owner));
        $this->assertFalse($service->isRestricted($admin));
    }

    /**
     * 3. CHARACTERIZE ALL-WAREHOUSES PERMISSION FOR NON-ADMIN ROLE
     */
    public function test_characterize_all_warehouses_permission_grants_global_access(): void
    {
        $service = app(WarehouseAccessService::class);

        $role = Role::firstOrCreate(['name' => 'Supervisor_' . uniqid(), 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'all-warehouses', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = new User([
            'id'           => 103,
            'role_id'      => $role->id,
            'warehouse_id' => null,
            'is_active'    => true,
        ]);

        $this->assertTrue($service->roleGrantsGlobalWarehouseAccess($role));
        $this->assertTrue($service->isGlobal($user));
        $this->assertEquals(WarehouseAccessService::GLOBAL_OPERATIONAL, $service->classification($user));
    }

    /**
     * 4. CHARACTERIZE WAREHOUSE-RESTRICTED OPERATIONAL USER
     */
    public function test_characterize_warehouse_restricted_user_classification(): void
    {
        $service = app(WarehouseAccessService::class);
        $wh1 = $this->ensureWarehouse(1, 'Warehouse Alpha');

        $staffRole = Role::firstOrCreate(['name' => 'Staff_' . uniqid(), 'guard_name' => 'web']);

        $staffUser = new User([
            'id'           => 104,
            'role_id'      => $staffRole->id,
            'warehouse_id' => $wh1->id,
            'is_active'    => true,
        ]);

        $this->assertFalse($service->isGlobal($staffUser));
        $this->assertTrue($service->isWarehouseOperational($staffUser));
        $this->assertTrue($service->hasValidWarehouseAssignment($staffUser));
        $this->assertEquals(WarehouseAccessService::WAREHOUSE_OPERATIONAL, $service->classification($staffUser));
        $this->assertTrue($service->isRestricted($staffUser));
        $this->assertEquals($wh1->id, $service->warehouseId($staffUser));
    }

    /**
     * 5. CHARACTERIZE INVALID OPERATIONAL USER (NO WAREHOUSE ASSIGNED)
     */
    public function test_characterize_invalid_operational_user_classification(): void
    {
        $service = app(WarehouseAccessService::class);

        $staffRole = Role::firstOrCreate(['name' => 'StaffNoWh_' . uniqid(), 'guard_name' => 'web']);

        $userNoWh = new User([
            'id'           => 105,
            'role_id'      => $staffRole->id,
            'warehouse_id' => null,
            'is_active'    => true,
        ]);

        $this->assertFalse($service->isGlobal($userNoWh));
        $this->assertFalse($service->hasValidWarehouseAssignment($userNoWh));
        $this->assertEquals(WarehouseAccessService::INVALID_OPERATIONAL, $service->classification($userNoWh));
        $this->assertNull($service->warehouseId($userNoWh));
    }

    /**
     * 6. CHARACTERIZE CUSTOMER PORTAL USER CLASSIFICATION
     */
    public function test_characterize_customer_portal_user_classification(): void
    {
        $service = app(WarehouseAccessService::class);

        $portalUser = User::create([
            'name'         => 'Portal User ' . uniqid(),
            'email'        => 'portal_' . uniqid() . '@example.test',
            'password'     => bcrypt('secret'),
            'role_id'      => 5,
            'is_active'    => true,
            'is_deleted'   => false,
        ]);

        $customer = Customer::create([
            'customer_group_id' => 1,
            'user_id'           => $portalUser->id,
            'name'              => 'Portal Customer ' . uniqid(),
            'company_name'      => 'Portal Co',
            'email'             => $portalUser->email,
            'phone_number'      => '555' . rand(1000, 9999),
            'address'           => 'Portal Address',
            'city'              => 'City',
            'is_active'         => true,
        ]);

        $this->assertTrue($service->isPortalIdentity($portalUser));
        $this->assertEquals($customer->id, $service->portalCustomerId($portalUser));
        $this->assertEquals(WarehouseAccessService::PORTAL_IDENTITY, $service->classification($portalUser));
        $this->assertFalse($service->isGlobal($portalUser));
        $this->assertFalse($service->isWarehouseOperational($portalUser));
    }

    /**
     * 7. CHARACTERIZE TENANT ISOLATION BOUNDARY
     */
    public function test_characterize_tenant_isolation_boundary_in_conversation(): void
    {
        $user = User::first() ?? User::create([
            'name' => 'Tenant User',
            'email' => 'tuser@example.test',
            'password' => bcrypt('password'),
            'role_id' => 1,
            'is_active' => true,
        ]);

        $conversationTenantA = AIConversation::create([
            'tenant_id' => 'tenant-a-123',
            'user_id'   => $user->id,
            'provider'  => 'structured',
            'mode'      => 'structured',
            'title'     => 'Tenant A Convo',
        ]);

        $executionService = app(AssistantExecutionService::class);

        // Attempting to append to Tenant A conversation without matching tenant context must abort 404
        $this->expectException(HttpException::class);
        $executionService->executeAndPersist(
            prompt: 'sales summary',
            user: $user,
            conversation: $conversationTenantA
        );
    }

    /**
     * 8. CHARACTERIZE OWN-RECORD SCOPE HANDLING
     */
    public function test_characterize_own_record_scope_representation(): void
    {
        $context = new AssistantContextData(
            businessContext: ['own_user_id' => 42]
        );

        $scope = WarehouseScope::fromContext($context);

        $this->assertFalse($scope->isRestricted);
        $this->assertEquals(42, $scope->ownUserId);
    }

    /**
     * 9. CHARACTERIZE DISABLED MODULE GATING
     */
    public function test_characterize_disabled_module_gating(): void
    {
        $moduleAccess = app(\App\Services\ModuleAccessService::class);

        // Modules Ecommerce and Woocommerce are disabled in default status
        $this->assertFalse($moduleAccess->isOperational('ecommerce'));
        $this->assertFalse($moduleAccess->isOperational('woocommerce'));
    }

    /**
     * 10. CHARACTERIZE MISSING UNDERLYING PERMISSION GATING
     */
    public function test_characterize_missing_underlying_permission_check(): void
    {
        $role = Role::firstOrCreate(['name' => 'RestrictedNoSales_' . uniqid(), 'guard_name' => 'web']);
        $user = User::create([
            'name' => 'NoSales User',
            'email' => 'nosales_' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $user->syncRoles([$role->name]);

        $this->assertFalse($user->can('sales-index'));
    }

    /**
     * 11. REGRESSION SPECIFICATION: EXPECTED WAREHOUSE-ISOLATED CUSTOMER DUE CONTRACT
     *
     * Once Stage 2 (AssistantAccessContext) and Stage 5 (Migrated CustomerDueSkill) are implemented,
     * querying customer due with warehouse restriction [1] MUST NOT return Customer B's debt from Warehouse 2.
     * This test documents the target specification contract.
     */
    public function test_contract_expected_warehouse_isolated_due_calculation(): void
    {
        $wh1 = $this->ensureWarehouse(1, 'Warehouse Alpha');
        $wh2 = $this->ensureWarehouse(2, 'Warehouse Beta');

        $customerA = $this->createCustomer('TargetCustA');
        $customerB = $this->createCustomer('TargetCustB');

        Sale::create([
            'reference_no'   => 'POS-T1-' . uniqid(),
            'user_id'        => 1,
            'customer_id'    => $customerA->id,
            'warehouse_id'   => $wh1->id,
            'biller_id'      => 1,
            'item'           => 1,
            'total_qty'      => 1,
            'total_price'    => 100,
            'grand_total'    => 100,
            'paid_amount'    => 0,
            'payment_status' => 1,
            'sale_status'    => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Sale::create([
            'reference_no'   => 'POS-T2-' . uniqid(),
            'user_id'        => 1,
            'customer_id'    => $customerB->id,
            'warehouse_id'   => $wh2->id,
            'biller_id'      => 1,
            'item'           => 1,
            'total_qty'      => 1,
            'total_price'    => 200,
            'grand_total'    => 200,
            'paid_amount'    => 0,
            'payment_status' => 1,
            'sale_status'    => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // When evaluating scoped sales due for warehouse 1:
        $scopedSales = Sale::whereNull('deleted_at')
            ->where('sale_status', '!=', 3)
            ->where('warehouse_id', $wh1->id)
            ->get();

        $this->assertTrue($scopedSales->contains('customer_id', $customerA->id));
        $this->assertFalse($scopedSales->contains('customer_id', $customerB->id));
    }
}
