<?php

namespace Modules\AIAssistant\Skills;

use App\Services\Read\FinancialReadService;
use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Services\LocalizedIntentMatcher;

class FinancialProfitLossSkill implements AssistantSkill
{
    private FinancialReadService $financialReadService;

    public function __construct(?FinancialReadService $financialReadService = null)
    {
        $this->financialReadService = $financialReadService ?? app(FinancialReadService::class);
    }

    public function key(): string
    {
        return 'financial_pnl';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_financial_pnl_name', [], 'en') ?: 'Financial Profit & Loss';
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_financial_pnl_description', [], 'en') ?: 'Authoritative double-entry GL Profit & Loss statement';
    }

    public function examples(): array
    {
        return [
            'show profit and loss',
            'p&l summary',
            'income statement',
            'gl financial profit',
        ];
    }

    public function canHandle(AssistantMessageData $message): bool
    {
        return LocalizedIntentMatcher::matchesSkill($this->key(), $message->content);
    }

    public function handle(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
    {
        $scope = WarehouseScope::fromContext($context);

        $result = $this->financialReadService->profitAndLoss($scope);

        if (!$result['is_authoritative']) {
            $msg = $result['unavailability_message'] ?? 'Financial statement unavailable because double-entry accounting is not currently authoritative for this business.';
            return new AssistantResponseData(
                textSummary: $msg,
                responseType: 'card',
                cards: [],
                warnings: [$msg],
                metadata: [
                    'skill' => $this->key(),
                    'failed_closed' => true,
                    'reason' => 'accounting_not_authoritative',
                    'profit_type' => 'gl_financial_profit',
                ]
            );
        }

        if (!empty($result['unavailability_message'])) {
            return new AssistantResponseData(
                textSummary: $result['unavailability_message'],
                responseType: 'card',
                cards: [],
                warnings: [$result['unavailability_message']],
                metadata: [
                    'skill' => $this->key(),
                    'failed_closed' => true,
                    'reason' => 'scope_restricted',
                    'profit_type' => 'gl_financial_profit',
                ]
            );
        }

        $cards = [
            ['title' => 'GL Net Profit', 'value' => $result['net_profit']],
            ['title' => 'Gross Profit', 'value' => $result['gross_profit']],
            ['title' => 'Net Revenue', 'value' => $result['net_revenue']],
            ['title' => 'Total Expenses', 'value' => $result['total_expenses']],
            ['title' => 'Total COGS', 'value' => $result['total_cogs']],
        ];

        $textSummary = sprintf(
            'GL Financial Profit & Loss: Net Revenue %.2f, COGS %.2f, Gross Profit %.2f, Expenses %.2f, Net Profit %.2f.',
            $result['net_revenue'],
            $result['total_cogs'],
            $result['gross_profit'],
            $result['total_expenses'],
            $result['net_profit']
        );

        return new AssistantResponseData(
            textSummary: $textSummary,
            responseType: 'card',
            cards: $cards,
            table: [],
            links: [
                ['label' => 'View P&L Report', 'url' => url('/accounting/reports/profit-and-loss')],
            ],
            metadata: [
                'skill' => $this->key(),
                'profit_type' => 'gl_financial_profit',
                'failed_closed' => false,
                'is_authoritative' => true,
            ]
        );
    }
}
