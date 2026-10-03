<?php

namespace Modules\AIAssistant\Tests\Unit;

use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\LocalizedIntentMatcher;
use Modules\AIAssistant\Services\StructuredQuestionEngine;
use Modules\AIAssistant\Skills\DailySnapshotSkill;
use Modules\AIAssistant\Specialists\SpecialistRegistry;
use Tests\TestCase;

class SpecialistOrchestrationTest extends TestCase
{
    public function test_salama_is_default_business_coordinator_specialist(): void
    {
        $registry = app(SpecialistRegistry::class);
        $default = $registry->getDefault();

        $this->assertEquals('business', $default->key);
        $this->assertEquals('Salama', $default->displayName);
        $this->assertEquals('Business Manager', $default->role);
        $this->assertTrue($default->enabled);
    }

    public function test_all_six_specialists_are_registered_with_roles(): void
    {
        $registry = app(SpecialistRegistry::class);
        $specialists = $registry->all();

        $this->assertCount(6, $specialists);

        $keys = array_map(fn($s) => $s->key, $specialists);
        $this->assertEquals(['business', 'sales', 'supply', 'finance', 'people', 'restaurant'], $keys);

        $names = array_map(fn($s) => $s->displayName, $specialists);
        $this->assertContains('Salama', $names);
        $this->assertContains('Jason', $names);
        $this->assertContains('Abdul', $names);
        $this->assertContains('Nora', $names);
        $this->assertContains('Maya', $names);
        $this->assertContains('Rami', $names);
    }

    public function test_specialist_prefix_intent_resolution(): void
    {
        // Jason (Sales)
        $this->assertEquals('sales_summary', LocalizedIntentMatcher::resolve('ask jason today sales'));
        $this->assertEquals('sales_summary', LocalizedIntentMatcher::resolve('jason sales summary'));
        $this->assertEquals('top_products', LocalizedIntentMatcher::resolve('ask jason top products'));

        // Abdul (Supply & Inventory)
        $this->assertEquals('low_stock', LocalizedIntentMatcher::resolve('ask abdul low stock'));
        $this->assertEquals('low_stock', LocalizedIntentMatcher::resolve('abdul low stock'));
        $this->assertEquals('purchase_summary', LocalizedIntentMatcher::resolve('ask abdul purchase summary'));

        // Nora (Finance)
        $this->assertEquals('cash_bank_summary', LocalizedIntentMatcher::resolve('ask nora cash bank summary'));
        $this->assertEquals('cash_bank_summary', LocalizedIntentMatcher::resolve('nora cash and bank summary'));
        $this->assertEquals('expense_summary', LocalizedIntentMatcher::resolve('ask nora todays expenses'));

        // Salama (Business)
        $this->assertEquals('daily_snapshot', LocalizedIntentMatcher::resolve('salama daily snapshot'));
        $this->assertEquals('daily_snapshot', LocalizedIntentMatcher::resolve('ask salama daily business snapshot'));
    }

    public function test_direct_intent_question_id_mappings(): void
    {
        $this->assertEquals('sales_summary', LocalizedIntentMatcher::resolve('intent:sales_today'));
        $this->assertEquals('purchase_summary', LocalizedIntentMatcher::resolve('intent:purchases_today'));
        $this->assertEquals('low_stock', LocalizedIntentMatcher::resolve('intent:low_stock_alerts'));
        $this->assertEquals('top_products', LocalizedIntentMatcher::resolve('intent:top_selling_products'));
        $this->assertEquals('customer_due', LocalizedIntentMatcher::resolve('intent:customer_dues'));
        $this->assertEquals('supplier_due', LocalizedIntentMatcher::resolve('intent:supplier_dues'));
        $this->assertEquals('cash_bank_summary', LocalizedIntentMatcher::resolve('intent:cash_bank_balances'));
        $this->assertEquals('daily_snapshot', LocalizedIntentMatcher::resolve('intent:todays_business_summary'));
    }

    public function test_salama_daily_snapshot_provides_coordinator_follow_ups(): void
    {
        $skill = new DailySnapshotSkill();
        $message = new AssistantMessageData('user', 'daily snapshot');
        $admin = new \App\Models\User(['id' => 1, 'role_id' => 1]);
        $accessContext = AssistantAccessContext::fromUser($admin);
        $context = new AssistantContextData(userId: 1, accessContext: $accessContext);

        $response = $skill->handle($message, $context);

        $this->assertEquals('Salama', $response->metadata['coordinator']);
        $this->assertEquals('business', $response->metadata['specialist']);
        $this->assertArrayHasKey('follow_up_suggestions', $response->metadata);

        $followUps = $response->metadata['follow_up_suggestions'];
        $this->assertNotEmpty($followUps);

        $specialistHandoffs = array_column($followUps, 'specialist');
        $this->assertContains('sales', $specialistHandoffs);
        $this->assertContains('supply', $specialistHandoffs);
        $this->assertContains('finance', $specialistHandoffs);
    }

    public function test_question_engine_groups_catalog_by_specialist(): void
    {
        $engine = app(StructuredQuestionEngine::class);
        $user = new \App\Models\User(['id' => 1, 'role_id' => 1]);
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

        $this->assertEquals('Salama', $grouped['business']['specialist']['display_name']);
        $this->assertEquals('Jason', $grouped['sales']['specialist']['display_name']);
        $this->assertEquals('Abdul', $grouped['supply']['specialist']['display_name']);
        $this->assertEquals('Nora', $grouped['finance']['specialist']['display_name']);
    }

    public function test_specialist_rename_updates_display_name_and_routing(): void
    {
        config([
            'aiassistant.specialists.sales.name' => 'Alex',
            'aiassistant.specialists.business.name' => 'Commander',
        ]);

        $registry = new SpecialistRegistry();
        $this->assertEquals('Alex', $registry->get('sales')->displayName);
        $this->assertStringContainsString('Alex', $registry->get('sales')->welcomeMessage);
        $this->assertEquals('Commander', $registry->get('business')->displayName);
        $this->assertStringContainsString('Commander', $registry->get('business')->welcomeMessage);

        // Prompt routing works with new configured names
        $this->assertEquals('sales_summary', LocalizedIntentMatcher::resolve('ask alex today sales'));
        $this->assertEquals('sales_summary', LocalizedIntentMatcher::resolve('alex sales summary'));
        $this->assertEquals('daily_snapshot', LocalizedIntentMatcher::resolve('ask commander daily business snapshot'));

        // Prompt routing also continues working with stable keys
        $this->assertEquals('sales_summary', LocalizedIntentMatcher::resolve('ask sales today sales'));
        $this->assertEquals('low_stock', LocalizedIntentMatcher::resolve('ask supply low stock'));
        $this->assertEquals('cash_bank_summary', LocalizedIntentMatcher::resolve('ask finance cash bank summary'));

        // Daily snapshot coordinator metadata reflects configured name
        $skill = new DailySnapshotSkill();
        $admin = new \App\Models\User(['id' => 1, 'role_id' => 1]);
        $accessContext = AssistantAccessContext::fromUser($admin);
        $response = $skill->handle(new AssistantMessageData('user', 'daily snapshot'), new AssistantContextData(userId: 1, accessContext: $accessContext));
        $this->assertEquals('Commander', $response->metadata['coordinator']);

        $followUps = $response->metadata['follow_up_suggestions'];
        $salesFollowUp = collect($followUps)->firstWhere('specialist', 'sales');
        $this->assertNotNull($salesFollowUp);
        $this->assertStringContainsString('Alex', $salesFollowUp['label']);
    }
}
