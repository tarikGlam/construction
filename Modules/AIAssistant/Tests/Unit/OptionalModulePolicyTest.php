<?php

namespace Modules\AIAssistant\Tests\Unit;

use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\AssistantOrchestrator;
use Modules\AIAssistant\Services\OptionalModulePolicy;
use Modules\AIAssistant\Services\SkillRegistry;
use Tests\TestCase;

class OptionalModulePolicyTest extends TestCase
{
    public function test_recognizes_deferred_optional_module_features(): void
    {
        $this->assertTrue(OptionalModulePolicy::isDeferred('table_occupancy'));
        $this->assertTrue(OptionalModulePolicy::isDeferred('open_table_orders'));
        $this->assertTrue(OptionalModulePolicy::isDeferred('today_attendance'));
        $this->assertTrue(OptionalModulePolicy::isDeferred('payroll_summary'));
        $this->assertTrue(OptionalModulePolicy::isDeferred('landed_cost_summary'));

        // Core features are not deferred
        $this->assertFalse(OptionalModulePolicy::isDeferred('sales_summary'));
        $this->assertFalse(OptionalModulePolicy::isDeferred('low_stock'));
        $this->assertFalse(OptionalModulePolicy::isDeferred('customer_due'));
    }

    public function test_deferred_message_contains_module_and_phase(): void
    {
        $msg = OptionalModulePolicy::getDeferredMessage('table_occupancy');
        $this->assertStringContainsString('Restaurant', $msg);
        $this->assertStringContainsString('Phase 2', $msg);

        $hrMsg = OptionalModulePolicy::getDeferredMessage('today_attendance');
        $this->assertStringContainsString('HR', $hrMsg);
        $this->assertStringContainsString('Phase 2', $hrMsg);
    }

    public function test_orchestrator_returns_module_disabled_for_deferred_intent(): void
    {
        $registry = app(SkillRegistry::class);
        $orchestrator = new AssistantOrchestrator($registry);

        $context = new AssistantContextData(
            tenantId: null,
            userId: 1,
            businessContext: ['parsed_skill' => 'table_occupancy'],
            accessContext: AssistantAccessContext::fromUser(null)
        );

        $message = new AssistantMessageData('user', 'intent:table_occupancy');
        $response = $orchestrator->executeStructured($message, $context);

        $this->assertSame('module_disabled', $response->resolveStatus());
        $this->assertSame('module_disabled', $response->toCanonical()['status']);
        $this->assertFalse($response->toCanonical()['availability']['is_available']);
        $this->assertStringContainsString('Restaurant', $response->textSummary);
    }
}
