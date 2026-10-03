<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Support\SeedsAiAssistantAccess;

class GuidedQuestionApiTest extends TestCase
{
    use DatabaseTransactions, SeedsAiAssistantAccess;

    protected User $adminUser;
    protected User $limitedUser;
    protected User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");

        // Base permissions
        $aiPerm = Permission::firstOrCreate(['name' => 'ai-assistant-index', 'guard_name' => 'web']);
        $salesPerm = Permission::firstOrCreate(['name' => 'sales-index', 'guard_name' => 'web']);
        $productsPerm = Permission::firstOrCreate(['name' => 'products-index', 'guard_name' => 'web']);
        $accountPerm = Permission::firstOrCreate(['name' => 'account-index', 'guard_name' => 'web']);
        $tablePerm = Permission::firstOrCreate(['name' => 'table', 'guard_name' => 'web']);

        // Roles
        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'guard_name' => 'web', 'is_active' => 1]);
        $adminRole = Role::find(1);
        $limitedRole = Role::firstOrCreate(['name' => 'Limited_' . uniqid(), 'guard_name' => 'web', 'is_active' => 1]);
        $noAiRole = Role::firstOrCreate(['name' => 'NoAi_' . uniqid(), 'guard_name' => 'web', 'is_active' => 1]);
        $this->enableAiAssistantForFixture();

        // Admin has all permissions
        DB::table('role_has_permissions')->insertOrIgnore([
            ['permission_id' => $aiPerm->id, 'role_id' => $adminRole->id],
            ['permission_id' => $salesPerm->id, 'role_id' => $adminRole->id],
            ['permission_id' => $productsPerm->id, 'role_id' => $adminRole->id],
            ['permission_id' => $accountPerm->id, 'role_id' => $adminRole->id],
            ['permission_id' => $tablePerm->id, 'role_id' => $adminRole->id],
        ]);

        // Limited user has only AI + sales permissions (lacks products, account, table)
        DB::table('role_has_permissions')->insertOrIgnore([
            ['permission_id' => $aiPerm->id, 'role_id' => $limitedRole->id],
            ['permission_id' => $salesPerm->id, 'role_id' => $limitedRole->id],
        ]);
        $this->grantAiAssistantAccess($limitedRole->id);

        // No AI role lacks ai-assistant-index
        DB::table('role_has_permissions')->insertOrIgnore([
            ['permission_id' => $salesPerm->id, 'role_id' => $noAiRole->id],
        ]);

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forget('role_has_permissions_list1');

        $this->adminUser = User::create([
            'name' => 'Admin Tester',
            'email' => 'admin_test_' . uniqid() . '@example.com',
            'phone' => '123456789' . rand(100, 999),
            'password' => bcrypt('secret'),
            'role_id' => $adminRole->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->limitedUser = User::create([
            'name' => 'Limited Tester',
            'email' => 'limited_test_' . uniqid() . '@example.com',
            'phone' => '123456789' . rand(100, 999),
            'password' => bcrypt('secret'),
            'role_id' => $limitedRole->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->unauthorizedUser = User::create([
            'name' => 'No AI Tester',
            'email' => 'no_ai_' . uniqid() . '@example.com',
            'phone' => '123456789' . rand(100, 999),
            'password' => bcrypt('secret'),
            'role_id' => $noAiRole->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        DB::table('general_settings')->where('id', 1)->update(['staff_access' => 'all']);
        Cache::forget('general_setting');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson(route('ai-assistant.questions.index'));
        $response->assertStatus(401);
    }

    public function test_user_without_ai_permission_is_forbidden(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)
            ->getJson(route('ai-assistant.questions.index'));

        $response->assertStatus(403);
    }

    public function test_authenticated_admin_receives_complete_guided_questions_payload(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson(route('ai-assistant.questions.index'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'suggestions',
            'grouped',
            'questions',
            'specialists',
        ]);

        $data = $response->json();

        // Must have suggestions
        $this->assertNotEmpty($data['suggestions']);

        // Must include all specialist definitions
        $specialistKeys = array_column($data['specialists'], 'key');
        $this->assertContains('business', $specialistKeys);
        $this->assertContains('sales', $specialistKeys);
        $this->assertContains('supply', $specialistKeys);
        $this->assertContains('finance', $specialistKeys);
        $this->assertContains('people', $specialistKeys);
        $this->assertContains('restaurant', $specialistKeys);
    }

    public function test_page_context_awareness_returns_contextual_suggestions(): void
    {
        // 1. Sales page context
        $salesResponse = $this->actingAs($this->adminUser)
            ->getJson(route('ai-assistant.questions.index', ['page' => 'sales.index']));

        $salesResponse->assertStatus(200);
        $salesSuggestions = $salesResponse->json('suggestions');
        $salesQuestionIds = array_column($salesSuggestions, 'id');

        $this->assertContains('sales_today', $salesQuestionIds);
        $this->assertContains('top_selling_products', $salesQuestionIds);

        // 2. Inventory / Products page context
        $productResponse = $this->actingAs($this->adminUser)
            ->getJson(route('ai-assistant.questions.index', ['page' => 'products.index']));

        $productResponse->assertStatus(200);
        $productSuggestions = $productResponse->json('suggestions');
        $productQuestionIds = array_column($productSuggestions, 'id');

        $this->assertContains('low_stock_alerts', $productQuestionIds);
        $this->assertContains('slow_moving_products', $productQuestionIds);
    }

    public function test_specialist_filtering_parameter(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson(route('ai-assistant.questions.index', ['specialist' => 'sales']));

        $response->assertStatus(200);
        $questions = $response->json('questions');
        $this->assertNotEmpty($questions);

        foreach ($questions as $q) {
            $this->assertEquals('sales', $q['specialist']);
        }
    }

    public function test_fail_closed_permission_filtering(): void
    {
        // Limited user has sales permission, but lacks table and account permissions
        $response = $this->actingAs($this->limitedUser)
            ->getJson(route('ai-assistant.questions.index'));

        $response->assertStatus(200);
        $allQuestions = $response->json('questions');
        $questionIds = array_column($allQuestions, 'id');

        // Can access sales
        $this->assertContains('sales_today', $questionIds);

        // Cannot access restaurant table or profit and loss
        $this->assertNotContains('table_occupancy', $questionIds);
        $this->assertNotContains('open_table_orders', $questionIds);
        $this->assertNotContains('profit_and_loss', $questionIds);
    }

    public function test_conversation_store_accepts_page_context(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('ai-assistant.conversations.store'), [
                'prompt' => 'intent:sales_today',
                'display_prompt' => "Today's Sales Summary",
                'page_context' => [
                    'page' => 'sales.index',
                    'warehouse_id' => 1,
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'conversation' => ['id', 'title'],
            'response' => ['text_summary', 'response_type'],
        ]);

        $this->assertEquals("Today's Sales Summary", $response->json('conversation.title'));
    }
}
