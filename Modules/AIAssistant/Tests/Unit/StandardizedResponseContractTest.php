<?php

namespace Modules\AIAssistant\Tests\Unit;

use Modules\AIAssistant\DTO\AssistantResponseData;
use Tests\TestCase;

class StandardizedResponseContractTest extends TestCase
{
    public function test_canonical_schema_has_required_keys(): void
    {
        $response = new AssistantResponseData(
            textSummary: 'Sales were good today.',
            responseType: 'card',
            cards: [['title' => 'Total', 'value' => 100]],
            metadata: ['skill' => 'sales_summary']
        );

        $canonical = $response->toCanonical();
        $expectedKeys = ['skill', 'status', 'specialist', 'summary', 'metrics', 'rows', 'context', 'availability', 'suggested_followups'];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $canonical, "Canonical schema missing key: {$key}");
        }

        $this->assertSame('sales_summary', $canonical['skill']);
        $this->assertSame('success', $canonical['status']);
        $this->assertSame('sales', $canonical['specialist']);
        $this->assertSame('Sales were good today.', $canonical['summary']);
        $this->assertNotEmpty($canonical['metrics']);
        $this->assertTrue($canonical['availability']['is_available']);
        $this->assertSame('available', $canonical['availability']['state']);
        $this->assertContains('top selling products', $canonical['suggested_followups']);
    }

    public function test_maps_status_empty(): void
    {
        $response = new AssistantResponseData(
            textSummary: 'No products sold today.',
            responseType: 'card',
            cards: [],
            table: [],
            metadata: ['skill' => 'top_products']
        );

        $this->assertSame('empty', $response->resolveStatus());
        $this->assertSame('empty', $response->toCanonical()['status']);
    }

    public function test_maps_status_permission_denied(): void
    {
        $response = new AssistantResponseData(
            textSummary: 'Access denied.',
            responseType: 'error',
            errors: ['Access denied.'],
            metadata: ['failed_closed' => true, 'reason_code' => 'PERMISSION_DENIED']
        );

        $this->assertSame('permission_denied', $response->resolveStatus());
        $this->assertFalse($response->toCanonical()['availability']['is_available']);
        $this->assertSame('permission_denied', $response->toCanonical()['availability']['state']);
    }

    public function test_maps_status_module_disabled(): void
    {
        $response = new AssistantResponseData(
            textSummary: 'Ecommerce is disabled.',
            responseType: 'error',
            errors: ['Module disabled.'],
            metadata: ['reason_code' => 'MODULE_DISABLED']
        );

        $this->assertSame('module_disabled', $response->resolveStatus());
        $this->assertFalse($response->toCanonical()['availability']['is_available']);
    }

    public function test_maps_status_unsupported_scope(): void
    {
        $response = new AssistantResponseData(
            textSummary: 'Cannot view dues for other staff.',
            responseType: 'card',
            metadata: ['failed_closed' => true, 'reason' => 'own_access_restriction']
        );

        $this->assertSame('unsupported_scope', $response->resolveStatus());
        $this->assertFalse($response->toCanonical()['availability']['is_available']);
    }

    public function test_maps_status_accounting_not_authoritative(): void
    {
        $response = new AssistantResponseData(
            textSummary: 'GL not authoritative.',
            responseType: 'card',
            metadata: ['reason' => 'accounting_not_authoritative']
        );

        $this->assertSame('accounting_not_authoritative', $response->resolveStatus());
        $this->assertFalse($response->toCanonical()['availability']['is_available']);
    }

    public function test_maps_status_clarification_required(): void
    {
        $response = new AssistantResponseData(
            textSummary: 'Multiple matches found.',
            responseType: 'card',
            metadata: [
                'status' => 'clarification_required',
                'clarification_choices' => [
                    ['prompt' => 'customer A due'],
                    ['prompt' => 'customer B due'],
                ],
            ]
        );

        $this->assertSame('clarification_required', $response->resolveStatus());
        $this->assertSame(['customer A due', 'customer B due'], $response->resolveSuggestedFollowups());
    }

    public function test_maps_specialist_domains(): void
    {
        $salesResp = new AssistantResponseData('summary', metadata: ['skill' => 'sales_summary']);
        $this->assertSame('sales', $salesResp->resolveSpecialist());

        $supplyResp = new AssistantResponseData('summary', metadata: ['skill' => 'low_stock']);
        $this->assertSame('supply', $supplyResp->resolveSpecialist());

        $financeResp = new AssistantResponseData('summary', metadata: ['skill' => 'cash_bank_summary']);
        $this->assertSame('finance', $financeResp->resolveSpecialist());

        $coordResp = new AssistantResponseData('summary', metadata: ['skill' => 'daily_snapshot']);
        $this->assertSame('business', $coordResp->resolveSpecialist());
    }

    public function test_backward_compatibility_preserved_in_to_array(): void
    {
        $response = new AssistantResponseData(
            textSummary: 'Test summary',
            responseType: 'card',
            cards: [['title' => 'Metric', 'value' => 42]],
            table: ['columns' => ['Col1'], 'rows' => [['Val1']]],
            metadata: ['skill' => 'sales_summary']
        );

        $array = $response->toArray();

        // Both canonical keys and legacy keys exist
        $this->assertSame('sales_summary', $array['skill']);
        $this->assertSame('success', $array['status']);
        $this->assertSame('sales', $array['specialist']);
        $this->assertSame('Test summary', $array['summary']);
        $this->assertSame('Test summary', $array['text_summary']);
        $this->assertSame('card', $array['response_type']);
        $this->assertSame([['title' => 'Metric', 'value' => 42]], $array['cards']);
        $this->assertSame([['title' => 'Metric', 'value' => 42]], $array['metrics']);
        $this->assertSame([['Val1']], $array['rows']);
    }
}
