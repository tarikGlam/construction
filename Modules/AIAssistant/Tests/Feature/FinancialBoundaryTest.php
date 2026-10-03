<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\AccountingConfig;
use App\Models\User;
use App\Services\AccountingModeService;
use App\Services\Read\CustomerReadService;
use App\Services\Read\FinancialReadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\SkillAvailabilityResult;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\SkillRegistry;
use Modules\AIAssistant\Skills\FinancialProfitLossSkill;
use Tests\TestCase;

class FinancialBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement("SET SESSION sql_mode=''");
    }

    public function test_gl_pnl_returns_structured_unavailability_when_double_entry_is_inactive(): void
    {
        AccountingConfig::updateOrCreate(['id' => 1], ['enabled' => 0, 'status' => 'inactive']);
        $this->assertFalse(app(AccountingModeService::class)->isDoubleEntryAuthoritative());

        $skill = new FinancialProfitLossSkill();
        $message = new AssistantMessageData('user', 'profit and loss');
        $context = new AssistantContextData(userId: 1);

        $response = $skill->handle($message, $context);

        $this->assertTrue($response->metadata['failed_closed']);
        $this->assertEquals('accounting_not_authoritative', $response->metadata['reason']);
        $this->assertEquals('gl_financial_profit', $response->metadata['profit_type']);
        $this->assertStringContainsString(
            'Financial statement unavailable because double-entry accounting is not currently authoritative for this business.',
            $response->textSummary
        );
        $this->assertEmpty($response->cards);
    }

    public function test_gl_pnl_succeeds_when_double_entry_is_authoritative(): void
    {
        AccountingConfig::updateOrCreate(['id' => 1], ['enabled' => 1, 'status' => 'active']);
        $this->assertTrue(app(AccountingModeService::class)->isDoubleEntryAuthoritative());

        $skill = new FinancialProfitLossSkill();
        $message = new AssistantMessageData('user', 'profit and loss');
        $context = new AssistantContextData(userId: 1);

        $response = $skill->handle($message, $context);

        $this->assertFalse($response->metadata['failed_closed']);
        $this->assertTrue($response->metadata['is_authoritative']);
        $this->assertEquals('gl_financial_profit', $response->metadata['profit_type']);
        $this->assertNotEmpty($response->cards);

        $cardTitles = array_column($response->cards, 'title');
        $this->assertContains('GL Net Profit', $cardTitles);
        $this->assertContains('Gross Profit', $cardTitles);
        $this->assertContains('Net Revenue', $cardTitles);
    }

    public function test_skill_registry_availability_check_blocks_accounting_skills_when_mode_is_inactive(): void
    {
        AccountingConfig::updateOrCreate(['id' => 1], ['enabled' => 0, 'status' => 'inactive']);
        $this->assertFalse(app(AccountingModeService::class)->isDoubleEntryAuthoritative());

        $user = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $accessContext = AssistantAccessContext::fromUser($user);

        $registry = new SkillRegistry();
        $registry->register(new FinancialProfitLossSkill());

        $availability = $registry->checkAvailability('financial_pnl', $accessContext);

        $this->assertFalse($availability->isAvailable);
        $this->assertEquals(SkillAvailabilityResult::ACCOUNTING_NOT_AUTHORITATIVE, $availability->state);
        $this->assertEquals('ACCOUNTING_NOT_AUTHORITATIVE', $availability->reasonCode);
    }

    public function test_gl_pnl_fails_closed_when_warehouse_restricted_user_has_no_warehouses(): void
    {
        AccountingConfig::updateOrCreate(['id' => 1], ['enabled' => 1, 'status' => 'active']);

        $skill = new FinancialProfitLossSkill();
        $message = new AssistantMessageData('user', 'profit and loss');
        $context = new AssistantContextData(userId: 1, businessContext: ['warehouse_ids' => []]);

        $response = $skill->handle($message, $context);

        $this->assertTrue($response->metadata['failed_closed']);
        $this->assertEquals('scope_restricted', $response->metadata['reason']);
        $this->assertStringContainsString('warehouse assignment is required', $response->textSummary);
    }

    public function test_gl_pnl_fails_closed_for_own_staff_access(): void
    {
        AccountingConfig::updateOrCreate(['id' => 1], ['enabled' => 1, 'status' => 'active']);

        $skill = new FinancialProfitLossSkill();
        $message = new AssistantMessageData('user', 'profit and loss');
        $context = new AssistantContextData(userId: 1, businessContext: ['own_user_id' => 1]);

        $response = $skill->handle($message, $context);

        $this->assertTrue($response->metadata['failed_closed']);
        $this->assertEquals('scope_restricted', $response->metadata['reason']);
        $this->assertStringContainsString('User-restricted scopes are not permitted', $response->textSummary);
    }

    public function test_operational_receivables_remain_separate_from_gl(): void
    {
        // Operational customer due is accessible regardless of double-entry GL status
        AccountingConfig::updateOrCreate(['id' => 1], ['enabled' => 0, 'status' => 'inactive']);
        $this->assertFalse(app(AccountingModeService::class)->isDoubleEntryAuthoritative());

        $customerService = app(CustomerReadService::class);
        $user = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($user);

        $dueResult = $customerService->dueList($context, 10);
        $this->assertIsArray($dueResult);
        $this->assertArrayHasKey('total_due', $dueResult);
        $this->assertArrayHasKey('customer_count', $dueResult);
    }
}
