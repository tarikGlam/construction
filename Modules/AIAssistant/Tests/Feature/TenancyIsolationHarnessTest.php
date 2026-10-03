<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Modules\AIAssistant\Entities\AIConversation;
use Modules\AIAssistant\Entities\AIMessage;
use Modules\AIAssistant\Entities\AIProviderSetting;
use Modules\AIAssistant\Entities\AISkillRun;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\AssistantExecutionService;
use Modules\AIAssistant\Services\AssistantOrchestrator;
use Modules\AIAssistant\Services\EloquentSkillRunRecorder;
use Modules\AIAssistant\Skills\SalesSummarySkill;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenancyIsolationHarnessTest extends TestCase
{
    use RefreshDatabase;

    private User $tenantUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['id' => 1], ['name' => 'Admin', 'guard_name' => 'web', 'is_active' => true]);
        Role::firstOrCreate(['id' => 2], ['name' => 'Owner', 'guard_name' => 'web', 'is_active' => true]);
        Permission::firstOrCreate(['name' => 'ai-assistant-index', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sales-index', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'Staff_' . uniqid(), 'guard_name' => 'web'], ['is_active' => true]);
        $role->givePermissionTo(['ai-assistant-index', 'sales-index']);

        $this->tenantUser = User::create([
            'name' => 'Multi-Tenant User',
            'email' => 'tenant_user_' . uniqid() . '@example.com',
            'phone' => '123456789',
            'password' => bcrypt('secret123'),
            'role_id' => $role->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $this->tenantUser->assignRole($role);
    }

    public function test_tenant_a_cannot_view_tenant_b_conversations_in_index(): void
    {
        // Conversation for Tenant Alpha
        $convA = AIConversation::create([
            'tenant_id' => 'tenant-alpha',
            'user_id' => $this->tenantUser->id,
            'provider' => 'structured',
            'mode' => 'structured',
            'title' => 'Alpha Conversation',
        ]);

        // Conversation for Tenant Beta
        $convB = AIConversation::create([
            'tenant_id' => 'tenant-beta',
            'user_id' => $this->tenantUser->id,
            'provider' => 'structured',
            'mode' => 'structured',
            'title' => 'Beta Conversation',
        ]);

        // Conversation for Landlord / Standalone (no tenant)
        $convCentral = AIConversation::create([
            'tenant_id' => null,
            'user_id' => $this->tenantUser->id,
            'provider' => 'structured',
            'mode' => 'structured',
            'title' => 'Central Conversation',
        ]);

        // Querying for Tenant Alpha
        $alphaResults = AIConversation::forUserAndTenant($this->tenantUser->id, 'tenant-alpha')->get();
        $this->assertCount(1, $alphaResults);
        $this->assertSame($convA->id, $alphaResults->first()->id);
        $this->assertSame('Alpha Conversation', $alphaResults->first()->title);

        // Querying for Tenant Beta
        $betaResults = AIConversation::forUserAndTenant($this->tenantUser->id, 'tenant-beta')->get();
        $this->assertCount(1, $betaResults);
        $this->assertSame($convB->id, $betaResults->first()->id);
        $this->assertSame('Beta Conversation', $betaResults->first()->title);

        // Querying for Standalone
        $centralResults = AIConversation::forUserAndTenant($this->tenantUser->id, null)->get();
        $this->assertCount(1, $centralResults);
        $this->assertSame($convCentral->id, $centralResults->first()->id);
    }

    public function test_tenant_a_cannot_view_tenant_b_conversation_by_id(): void
    {
        $convBeta = AIConversation::create([
            'tenant_id' => 'tenant-beta',
            'user_id' => $this->tenantUser->id,
            'provider' => 'structured',
            'mode' => 'structured',
            'title' => 'Private Beta Data',
        ]);

        // Tenant Alpha querying Beta's ID must resolve to null
        $isolated = AIConversation::forUserAndTenant($this->tenantUser->id, 'tenant-alpha')
            ->find($convBeta->id);

        $this->assertNull($isolated);
    }

    public function test_assistant_execution_service_aborts_on_cross_tenant_conversation_append(): void
    {
        $convBeta = AIConversation::create([
            'tenant_id' => 'tenant-beta',
            'user_id' => $this->tenantUser->id,
            'provider' => 'structured',
            'mode' => 'structured',
            'title' => 'Beta Conversation',
        ]);

        /** @var AssistantExecutionService $executionService */
        $executionService = app(AssistantExecutionService::class);

        // In standalone/default context without active tenant matching tenant-beta, appending must abort 404
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('');

        $executionService->executeAndPersist(
            prompt: 'sales summary',
            user: $this->tenantUser,
            conversation: $convBeta
        );
    }

    public function test_skill_runs_are_strictly_isolated_by_tenant(): void
    {
        $recorder = new EloquentSkillRunRecorder();
        $skill = new SalesSummarySkill();
        $message = new AssistantMessageData('user', 'sales summary');
        $response = new AssistantResponseData('Sales summary text', metadata: ['skill' => 'sales_summary']);

        // Record run for Tenant Alpha
        $contextAlpha = new AssistantContextData(
            tenantId: 'tenant-alpha',
            userId: $this->tenantUser->id,
            accessContext: AssistantAccessContext::fromUser($this->tenantUser, [], 'tenant-alpha')
        );
        $recorder->record($skill, $message, $contextAlpha, $response, 15);

        // Record run for Tenant Beta
        $contextBeta = new AssistantContextData(
            tenantId: 'tenant-beta',
            userId: $this->tenantUser->id,
            accessContext: AssistantAccessContext::fromUser($this->tenantUser, [], 'tenant-beta')
        );
        $recorder->record($skill, $message, $contextBeta, $response, 20);

        // Assert DB isolation
        $alphaRuns = AISkillRun::where('tenant_id', 'tenant-alpha')->get();
        $betaRuns = AISkillRun::where('tenant_id', 'tenant-beta')->get();

        $this->assertCount(1, $alphaRuns);
        $this->assertCount(1, $betaRuns);
        $this->assertSame('tenant-alpha', $alphaRuns->first()->tenant_id);
        $this->assertSame('tenant-beta', $betaRuns->first()->tenant_id);
    }

    public function test_ai_provider_settings_isolated_by_tenant(): void
    {
        // Tenant A OpenAI config
        $settingA = AIProviderSetting::create([
            'tenant_id' => 'tenant-alpha',
            'provider' => 'openai',
            'api_key' => 'sk-alpha-key',
            'model' => 'gpt-4o',
            'is_enabled' => true,
        ]);

        // Tenant B OpenAI config
        $settingB = AIProviderSetting::create([
            'tenant_id' => 'tenant-beta',
            'provider' => 'openai',
            'api_key' => 'sk-beta-key',
            'model' => 'gpt-4o-mini',
            'is_enabled' => false,
        ]);

        // Assert separate records coexist under unique composite index
        $this->assertNotSame($settingA->id, $settingB->id);
        $this->assertSame('sk-alpha-key', AIProviderSetting::where('tenant_id', 'tenant-alpha')->first()->api_key);
        $this->assertSame('sk-beta-key', AIProviderSetting::where('tenant_id', 'tenant-beta')->first()->api_key);
    }

    public function test_assistant_access_context_preserves_tenant_id(): void
    {
        $contextAlpha = AssistantAccessContext::fromUser($this->tenantUser, [], 'tenant-alpha');
        $this->assertSame('tenant-alpha', $contextAlpha->tenantId);

        $contextBeta = AssistantAccessContext::fromUser($this->tenantUser, [], 'tenant-beta');
        $this->assertSame('tenant-beta', $contextBeta->tenantId);
    }
}
