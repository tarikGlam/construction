<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Modules\AIAssistant\DTO\SkillDefinition;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\AssistantOrchestrator;
use Modules\AIAssistant\Services\SkillRegistry;

class ExecutionAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $translations = collect(include database_path('seeders/Tenant/translations/en.php'))
            ->filter(fn (array $row) => str_starts_with($row['key'], 'ai_assistant'))
            ->mapWithKeys(fn (array $row) => ['db.'.$row['key'] => $row['value']])
            ->all();
        app('translator')->addLines($translations, 'en');
        app()->setLocale('en');
    }

    private function createFakeSkill(string $key, bool $handles = true, ?callable $handleCallback = null): AssistantSkill
    {
        return new class($key, $handles, $handleCallback) implements AssistantSkill {
            public int $callCount = 0;

            public function __construct(
                private string $key,
                private bool $handles,
                private $handleCallback
            ) {}

            public function key(): string { return $this->key; }
            public function name(): string { return $this->key . ' name'; }
            public function description(): string { return 'desc'; }
            public function examples(): array { return []; }

            public function canHandle(AssistantMessageData $message): bool
            {
                return $this->handles;
            }

            public function handle(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
            {
                $this->callCount++;
                if ($this->handleCallback) {
                    return ($this->handleCallback)($message, $context);
                }
                return new AssistantResponseData(textSummary: "Handled by {$this->key}");
            }
        };
    }

    private function createStaffUserWithPermissions(array $permissionNames): User
    {
        $role = Role::create([
            'name' => 'StaffRole_' . uniqid(),
            'guard_name' => 'web',
            'is_active' => true,
        ]);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->givePermissionTo($permission);
        }

        return new User([
            'id' => rand(100, 999),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function test_unauthorized_user_is_denied_at_orchestrator_execution_time(): void
    {
        $registry = new SkillRegistry();
        $skill = $this->createFakeSkill('sales_summary');

        $definition = new SkillDefinition(
            skillId: 'sales_summary',
            specialty: 'sales',
            requiredPermissions: ['sales-index'],
            permissionPolicy: 'all',
            handler: $skill
        );
        $registry->registerDefinition($definition);

        $orchestrator = new AssistantOrchestrator($registry);

        // User without sales-index
        $user = $this->createStaffUserWithPermissions([]);
        $accessContext = AssistantAccessContext::fromUser($user);
        $context = new AssistantContextData(userId: $user->id, accessContext: $accessContext);

        $message = new AssistantMessageData(role: 'user', content: 'sales summary');
        $response = $orchestrator->executeStructured($message, $context);

        $this->assertEquals('error', $response->responseType);
        $this->assertTrue($response->metadata['failed_closed'] ?? false);
        $this->assertEquals('PERMISSION_DENIED', $response->metadata['reason_code'] ?? null);
        $this->assertNotEmpty($response->errors);
        $this->assertEquals(0, $skill->callCount, 'Skill handle must not be called when permission is denied');
    }

    public function test_multi_permission_skill_fails_closed_when_user_has_only_one_permission(): void
    {
        $registry = new SkillRegistry();
        $skill = $this->createFakeSkill('customer_due');

        $definition = new SkillDefinition(
            skillId: 'customer_due',
            specialty: 'sales',
            requiredPermissions: ['customers-index', 'sales-index'],
            permissionPolicy: 'all',
            handler: $skill
        );
        $registry->registerDefinition($definition);

        $orchestrator = new AssistantOrchestrator($registry);

        // User has only sales-index, lacks customers-index
        $user = $this->createStaffUserWithPermissions(['sales-index']);
        $accessContext = AssistantAccessContext::fromUser($user);
        $context = new AssistantContextData(userId: $user->id, accessContext: $accessContext);

        $message = new AssistantMessageData(role: 'user', content: 'customer due');
        $response = $orchestrator->executeStructured($message, $context);

        $this->assertEquals('error', $response->responseType);
        $this->assertTrue($response->metadata['failed_closed'] ?? false);
        $this->assertEquals('PERMISSION_DENIED', $response->metadata['reason_code'] ?? null);
        $this->assertEquals(0, $skill->callCount, 'Skill handle must not be called when any required permission is missing');
    }

    public function test_authorized_user_executes_successfully_and_returns_exact_response(): void
    {
        $registry = new SkillRegistry();
        $expectedResponse = new AssistantResponseData(textSummary: 'Exact Response');
        $skill = $this->createFakeSkill('customer_due', true, fn() => $expectedResponse);

        $definition = new SkillDefinition(
            skillId: 'customer_due',
            specialty: 'sales',
            requiredPermissions: ['customers-index', 'sales-index'],
            permissionPolicy: 'all',
            handler: $skill
        );
        $registry->registerDefinition($definition);

        $orchestrator = new AssistantOrchestrator($registry);

        // User has both required permissions
        $user = $this->createStaffUserWithPermissions(['customers-index', 'sales-index']);
        $accessContext = AssistantAccessContext::fromUser($user);
        $context = new AssistantContextData(userId: $user->id, accessContext: $accessContext);

        $message = new AssistantMessageData(role: 'user', content: 'customer due');
        $response = $orchestrator->executeStructured($message, $context);

        $this->assertSame($expectedResponse, $response, 'Authorized execution must return the exact AssistantResponseData object');
        $this->assertEquals(1, $skill->callCount);
    }

    public function test_module_disabled_fails_closed_at_orchestrator_execution_time(): void
    {
        $registry = new SkillRegistry();
        $skill = $this->createFakeSkill('repair_summary');

        $definition = new SkillDefinition(
            skillId: 'repair_summary',
            specialty: 'business',
            moduleRequirement: 'NonExistentModule',
            handler: $skill
        );
        $registry->registerDefinition($definition);

        $orchestrator = new AssistantOrchestrator($registry);

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $accessContext = AssistantAccessContext::fromUser($admin);
        $context = new AssistantContextData(userId: 1, accessContext: $accessContext);

        $message = new AssistantMessageData(role: 'user', content: 'repair summary');
        $response = $orchestrator->executeStructured($message, $context);

        $this->assertEquals('error', $response->responseType);
        $this->assertTrue($response->metadata['failed_closed'] ?? false);
        $this->assertEquals('MODULE_DISABLED', $response->metadata['reason_code'] ?? null);
        $this->assertEquals(0, $skill->callCount);
    }
}
