<?php

namespace Modules\AIAssistant\Skills;

use App\Services\Read\ExpenseReadService;
use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Modules\AIAssistant\DTO\WarehouseScope;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ExpenseSummarySkill implements AssistantSkill
{
    private const RESULT_LIMIT = 10;

    public function __construct(
        private ?ExpenseReadService $expenseReadService = null
    ) {
        $this->expenseReadService ??= app(ExpenseReadService::class);
    }

    public function key(): string
    {
        return 'expense_summary';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_expense_name');
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_expense_description');
    }

    public function examples(): array
    {
        return [
            'today\'s expense summary',
            'expense summary',
            'show today\'s expenses',
            'how much did we spend today',
            'expenses today'
        ];
    }

    public function canHandle(AssistantMessageData $message): bool
    {
        return \Modules\AIAssistant\Services\LocalizedIntentMatcher::matchesSkill($this->key(), $message->content);
    }

    public function handle(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
    {
        $scope = WarehouseScope::fromContext($context);

        if ($scope->isRestricted && empty($scope->warehouseIds)) {
            return $this->buildResponse(0.0, 0, [], $scope->isRestricted, $scope->warehouseIds, $scope->ownUserId, true, 'empty_warehouse_scope');
        }

        $parsedParams = $context->businessContext['parsed_parameters'] ?? [];
        $startDate = $parsedParams['date_range']['start_date'] ?? Carbon::today()->toDateString();
        $endDate = $parsedParams['date_range']['end_date'] ?? Carbon::today()->toDateString();

        $summary = $this->expenseReadService->summary($scope, $startDate, $endDate);
        $totalExpense = (float) $summary['total_expenses'];
        $expenseCount = (int) $summary['expense_count'];

        $breakdowns = $this->expenseReadService->categoryBreakdown($scope, $startDate, $endDate, self::RESULT_LIMIT);
        $tableRows = [];
        foreach ($breakdowns as $cat) {
            $tableRows[] = [
                'category' => $cat['category'],
                'amount' => (float) $cat['total'],
            ];
        }

        return $this->buildResponse($totalExpense, $expenseCount, $tableRows, $scope->isRestricted, $scope->warehouseIds, $scope->ownUserId);
    }

    private function buildResponse(float $total, int $count, array $tableRows, bool $isRestricted, mixed $warehouseIds, ?int $ownUserId, bool $failedClosed = false, ?string $reason = null): AssistantResponseData
    {
        $textSummary = $count === 0
            ? __('db.ai_assistant_expense_empty')
            : __('db.ai_assistant_expense_summary', ['total' => number_format($total, 2), 'count' => $count]);

        $warnings = [];
        if ($failedClosed && $reason === 'empty_warehouse_scope') {
            $textSummary = __('db.ai_assistant_expense_empty_scope');
            $warnings[] = __('db.ai_assistant_warning_no_warehouse');
        }

        $cards = [
            ['title' => __('db.ai_assistant_card_todays_expenses'), 'value' => $count],
            ['title' => __('db.ai_assistant_card_total_amount'), 'value' => round($total, 2)],
        ];

        $table = [];
        if (!empty($tableRows)) {
            $table = [
                'columns' => [__('db.ai_assistant_column_category'), __('db.ai_assistant_column_amount')],
                'rows' => array_map(fn($r) => [
                    $r['category'],
                    $r['amount'],
                ], $tableRows),
            ];
        }

        return new AssistantResponseData(
            textSummary: $textSummary,
            responseType: 'card',
            cards: $cards,
            table: $table,
            links: [
                ['label' => __('db.ai_assistant_link_view_expenses'), 'url' => url('/expenses')]
            ],
            warnings: $warnings,
            metadata: [
                'skill' => $this->key(),
                'result_limit' => self::RESULT_LIMIT,
                'failed_closed' => $failedClosed,
                'reason' => $reason,
                'warehouse_ids' => $warehouseIds,
                'own_user_id' => $ownUserId,
                'date' => Carbon::today()->format('Y-m-d'),
            ]
        );
    }
}
