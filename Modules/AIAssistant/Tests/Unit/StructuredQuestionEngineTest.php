<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use Modules\AIAssistant\Services\StructuredQuestionEngine;
use Modules\AIAssistant\Security\AssistantAccessContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

class StructuredQuestionEngineTest extends TestCase
{
    use DatabaseTransactions;

    private StructuredQuestionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(StructuredQuestionEngine::class);
    }

    private function createAdminContext(
        bool $isRestrictedWarehouse = false,
        array $warehouseIds = [],
        ?int $ownUserId = null,
        bool $isPortalUser = false
    ): AssistantAccessContext {
        $admin = User::firstOrCreate(
            ['email' => 'admin_test_sqe@example.com'],
            ['name' => 'Admin Test', 'phone' => '1234567890', 'password' => bcrypt('password'), 'role_id' => 1, 'is_active' => true, 'is_deleted' => false]
        );

        return new AssistantAccessContext(
            user: $admin,
            tenantId: null,
            warehouseClassification: $isRestrictedWarehouse ? 'warehouse_restricted' : 'all_warehouses',
            isGlobalWarehouseAccess: !$isRestrictedWarehouse,
            isRestrictedWarehouseAccess: $isRestrictedWarehouse,
            allowedWarehouseIds: $warehouseIds,
            isPortalUser: $isPortalUser,
            portalCustomerId: null,
            ownUserId: $ownUserId,
            pageContext: []
        );
    }

    public function test_default_catalog_is_registered()
    {
        $all = $this->engine->all();

        $this->assertNotEmpty($all);
        $this->assertArrayHasKey('daily_snapshot', $all);
        $this->assertArrayHasKey('sales_today', $all);
        $this->assertArrayHasKey('low_stock_alerts', $all);
        $this->assertArrayHasKey('cash_bank_balances', $all);
        $this->assertArrayHasKey('todays_expenses', $all);
        $this->assertArrayHasKey('profit_and_loss', $all);
    }

    public function test_permissions_filtering_fail_closed()
    {
        $roleId = 4;
        DB::table('roles')->updateOrInsert(['id' => $roleId], ['name' => 'StaffRole'.$roleId, 'guard_name' => 'web', 'is_active' => true]);

        // Remove any prior permissions for this role
        DB::table('role_has_permissions')->where('role_id', $roleId)->delete();

        // Grant only sales-index permission to role 4
        $salesPermId = DB::table('permissions')->where('name', 'sales-index')->value('id');
        if (!$salesPermId) {
            $salesPermId = DB::table('permissions')->insertGetId([
                'name' => 'sales-index',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('role_has_permissions')->insert(['permission_id' => $salesPermId, 'role_id' => $roleId]);
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Illuminate\Support\Facades\Cache::forget("salepro.permissions.role.{$roleId}.web");
        \Illuminate\Support\Facades\Cache::forget("salepro.permissions.all.web");
        \Illuminate\Support\Facades\Cache::forget("role_has_permissions_list{$roleId}");

        $restrictedUser = User::create([
            'name' => 'Restricted Staff',
            'email' => 'staff_'.uniqid().'@example.com',
            'phone' => '1234567890',
            'password' => bcrypt('password'),
            'role_id' => $roleId,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $context = new AssistantAccessContext(
            user: $restrictedUser,
            tenantId: null,
            warehouseClassification: 'all_warehouses',
            isGlobalWarehouseAccess: true,
            isRestrictedWarehouseAccess: false,
            allowedWarehouseIds: [],
            isPortalUser: false,
            portalCustomerId: null,
            ownUserId: null,
            pageContext: []
        );

        $questions = $this->engine->getAvailableQuestions($context);
        $ids = array_map(fn($q) => $q->id, $questions);

        $this->assertContains('sales_today', $ids);
        $this->assertNotContains('cash_bank_balances', $ids);
        $this->assertNotContains('todays_expenses', $ids);
    }

    public function test_module_requirement_filtering()
    {
        // Register a question requiring a non-existent or disabled module
        $dummyQuestion = new \Modules\AIAssistant\DTO\QuestionDefinition(
            id: 'dummy_disabled_module_q',
            label: 'Disabled Module Question',
            prompt: 'disabled module test',
            specialist: 'business',
            category: 'Testing',
            targetSkillKey: 'daily_snapshot',
            requiredPermissions: [],
            moduleRequirement: 'non_existent_dummy_module_xyz'
        );
        $this->engine->register($dummyQuestion);

        $context = $this->createAdminContext();
        $questions = $this->engine->getAvailableQuestions($context);
        $ids = array_map(fn($q) => $q->id, $questions);

        $this->assertNotContains('dummy_disabled_module_q', $ids);
    }

    public function test_own_user_staff_restrictions()
    {
        $context = $this->createAdminContext(ownUserId: 42);

        $questions = $this->engine->getAvailableQuestions($context);
        $ids = array_map(fn($q) => $q->id, $questions);

        $this->assertNotContains('profit_and_loss', $ids);
        $this->assertNotContains('customer_dues', $ids);
        $this->assertNotContains('supplier_dues', $ids);
        $this->assertNotContains('cash_bank_balances', $ids);
    }

    public function test_warehouse_restrictions_fail_closed()
    {
        $context = $this->createAdminContext(
            isRestrictedWarehouse: true,
            warehouseIds: [] // No warehouse assigned!
        );

        $questions = $this->engine->getAvailableQuestions($context);
        $ids = array_map(fn($q) => $q->id, $questions);

        $this->assertNotContains('low_stock_alerts', $ids);
        $this->assertNotContains('slow_moving_products', $ids);
    }

    public function test_page_suggestions_match_routes()
    {
        $context = $this->createAdminContext();

        $salesSuggestions = $this->engine->getSuggestionsForPage($context, 'sales.index', 4);
        $salesIds = array_map(fn($q) => $q->id, $salesSuggestions);

        $this->assertContains('sales_today', $salesIds);
        $this->assertLessThanOrEqual(4, count($salesSuggestions));

        $productSuggestions = $this->engine->getSuggestionsForPage($context, 'products.index', 4);
        $productIds = array_map(fn($q) => $q->id, $productSuggestions);

        $this->assertContains('low_stock_alerts', $productIds);
    }

    public function test_grouped_by_specialist()
    {
        $context = $this->createAdminContext();

        $grouped = $this->engine->getAvailableGrouped($context);

        $this->assertArrayHasKey('business', $grouped);
        $this->assertArrayHasKey('sales', $grouped);
        $this->assertArrayHasKey('supply', $grouped);
        $this->assertArrayHasKey('finance', $grouped);

        $this->assertEquals('Salama', $grouped['business']['specialist']['display_name']);
        $this->assertEquals('Jason', $grouped['sales']['specialist']['display_name']);
        $this->assertEquals('Abdul', $grouped['supply']['specialist']['display_name']);
        $this->assertEquals('Nora', $grouped['finance']['specialist']['display_name']);
    }

    public function test_every_guided_question_target_resolves_to_registered_executable_skill(): void
    {
        $registry = app(\Modules\AIAssistant\Services\SkillRegistry::class);
        $all = $this->engine->all();

        $this->assertNotEmpty($all);
        foreach ($all as $id => $question) {
            $skill = $registry->get($question->targetSkillKey);
            $this->assertNotNull(
                $skill,
                "Guided question '{$id}' references target skill '{$question->targetSkillKey}' which is not registered in SkillRegistry."
            );
            $this->assertInstanceOf(
                \Modules\AIAssistant\Contracts\AssistantSkill::class,
                $skill,
                "Target skill '{$question->targetSkillKey}' is not an executable AssistantSkill."
            );
        }
    }
}
