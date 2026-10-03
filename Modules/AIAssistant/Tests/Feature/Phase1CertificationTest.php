<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\AssistantOrchestrator;
use Modules\AIAssistant\Services\LocalizedIntentMatcher;
use Modules\AIAssistant\Services\SkillRegistry;
use Modules\AIAssistant\Services\StructuredQuestionEngine;
use Modules\AIAssistant\Specialists\SpecialistRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase1CertificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET SESSION sql_mode=''");
        Http::preventStrayRequests(); // Absolute verification: zero external network calls allowed

        DB::table('roles')->updateOrInsert(
            ['id' => 1],
            ['name' => 'Admin', 'guard_name' => 'web', 'is_active' => true]
        );

        DB::table('users')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'Admin',
                'email' => 'admin_cert@example.com',
                'password' => bcrypt('password'),
                'role_id' => 1,
                'is_active' => true,
                'is_deleted' => false
            ]
        );
    }

    public function test_certification_zero_external_api_calls(): void
    {
        // Execute multiple skills through the orchestrator to confirm zero outbound HTTP requests
        $orchestrator = app(AssistantOrchestrator::class);
        $context = new AssistantContextData(userId: 1);

        $prompts = [
            'today sales',
            'today purchases',
            'daily business snapshot',
            'todays expense summary',
            'low stock',
            'top selling products',
            'slow moving products',
            'customer due summary',
            'supplier due summary',
            'cash and bank summary',
        ];

        foreach ($prompts as $prompt) {
            $msg = new AssistantMessageData('user', $prompt);
            $response = $orchestrator->executeStructured($msg, $context);
            $this->assertNotEmpty($response->textSummary, "Prompt '$prompt' returned empty summary.");
            $this->assertNotEquals('error', $response->responseType);
        }

        Http::assertNothingSent();
    }

    public function test_certification_all_specialists_registered_and_routable(): void
    {
        $specialistRegistry = app(SpecialistRegistry::class);
        $specialists = $specialistRegistry->all();

        $this->assertCount(6, $specialists);
        $expectedSpecialists = ['business', 'sales', 'supply', 'finance', 'people', 'restaurant'];
        $actualKeys = array_map(fn($s) => $s->key, $specialists);
        $this->assertEquals($expectedSpecialists, $actualKeys);

        // Verify routing to each specialist
        $this->assertEquals('sales_summary', LocalizedIntentMatcher::resolve('ask jason today sales'));
        $this->assertEquals('low_stock', LocalizedIntentMatcher::resolve('ask abdul low stock'));
        $this->assertEquals('cash_bank_summary', LocalizedIntentMatcher::resolve('ask nora cash bank summary'));
        $this->assertEquals('daily_snapshot', LocalizedIntentMatcher::resolve('salama daily snapshot'));
    }

    public function test_certification_question_engine_catalogs_and_suggests(): void
    {
        $engine = app(StructuredQuestionEngine::class);
        $user = new User(['id' => 1, 'role_id' => 1]);
        $context = new AssistantAccessContext(
            user: $user,
            tenantId: null,
            warehouseClassification: 'global',
            isGlobalWarehouseAccess: true,
            isRestrictedWarehouseAccess: false,
            allowedWarehouseIds: []
        );

        $grouped = $engine->getAvailableGrouped($context);
        $this->assertArrayHasKey('business', $grouped);
        $this->assertArrayHasKey('sales', $grouped);
        $this->assertArrayHasKey('supply', $grouped);
        $this->assertArrayHasKey('finance', $grouped);

        // Page contextual suggestion checks
        $salesSuggestions = $engine->getSuggestionsForPage($context, 'sales.index');
        $this->assertNotEmpty($salesSuggestions);
        $this->assertContains('sales_today', array_column($salesSuggestions, 'id'));

        $inventorySuggestions = $engine->getSuggestionsForPage($context, 'products.index');
        $this->assertNotEmpty($inventorySuggestions);
        $this->assertContains('low_stock_alerts', array_column($inventorySuggestions, 'id'));
    }

    public function test_certification_strict_warehouse_isolation(): void
    {
        $skill = app(\Modules\AIAssistant\Skills\LowStockSkill::class);

        $accessContext = new AssistantAccessContext(
            user: new User(['id' => 99, 'role_id' => 3]),
            tenantId: null,
            warehouseClassification: 'restricted',
            isGlobalWarehouseAccess: false,
            isRestrictedWarehouseAccess: true,
            allowedWarehouseIds: []
        );

        $emptyContext = new AssistantContextData(
            userId: 99,
            businessContext: $accessContext->toBusinessContext(),
            accessContext: $accessContext
        );

        $response = $skill->handle(new AssistantMessageData('user', 'low stock'), $emptyContext);

        $this->assertEquals(0, $response->cards[0]['value']);
        $this->assertEmpty($response->table);
        $this->assertEquals([], $response->metadata['warehouse_ids']);
    }
}
