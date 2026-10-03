<?php

namespace Modules\AIAssistant\Skills;

use App\Services\Read\SalesReadService;
use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Illuminate\Support\Carbon;

class SalesSummarySkill implements AssistantSkill
{
    public function __construct(
        private ?SalesReadService $salesReadService = null
    ) {
        $this->salesReadService ??= app(SalesReadService::class);
    }

    public function key(): string
    {
        return 'sales_summary';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_sales_name');
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_sales_description');
    }

    public function examples(): array
    {
        return [
            'show today\'s sales',
            'sales summary'
        ];
    }

    public function canHandle(AssistantMessageData $message): bool
    {
        return \Modules\AIAssistant\Services\LocalizedIntentMatcher::matchesSkill($this->key(), $message->content);
    }

    public function handle(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
    {
        $scope = $context->resolveWarehouseScope();

        if ($scope->isRestricted && empty($scope->warehouseIds)) {
            return $this->buildResponse(0, 0, 0, 0, 0, $scope->warehouseIds, $scope->ownUserId);
        }

        $parsedParams = $context->businessContext['parsed_parameters'] ?? [];
        $startDate = $parsedParams['date_range']['start_date'] ?? Carbon::today()->toDateString();
        $endDate = $parsedParams['date_range']['end_date'] ?? Carbon::today()->toDateString();

        $summary = $this->salesReadService->summary(
            context: $scope,
            startDate: $startDate,
            endDate: $endDate
        );

        return $this->buildResponse(
            (int) $summary['total_orders'],
            (float) $summary['total_items'],
            (float) $summary['total_sales'],
            (float) $summary['paid_amount'],
            (float) $summary['due_amount'],
            $scope->isRestricted ? $scope->warehouseIds : null,
            $scope->ownUserId
        );
    }

    private function buildResponse(int $count, float $qty, float $total, float $paid, float $due, ?array $warehouseIds, ?int $ownUserId): AssistantResponseData
    {
        $textSummary = __('db.ai_assistant_sales_summary', ['count' => $count, 'total' => number_format($total, 2)]);

        $cards = [
            ['title' => __('db.ai_assistant_card_transactions'), 'value' => $count],
            ['title' => __('db.ai_assistant_card_items_sold'), 'value' => $qty],
            ['title' => __('db.ai_assistant_card_gross_total'), 'value' => $total],
            ['title' => __('db.ai_assistant_card_paid_amount'), 'value' => $paid],
            ['title' => __('db.ai_assistant_card_due_amount'), 'value' => $due],
        ];

        return new AssistantResponseData(
            textSummary: $textSummary,
            responseType: 'card',
            cards: $cards,
            links: [
                ['label' => __('db.ai_assistant_link_view_sales'), 'url' => url('/sales')]
            ],
            metadata: [
                'skill' => $this->key(),
                'date' => Carbon::today()->toDateString(),
                'warehouse_ids' => $warehouseIds,
                'own_user_id' => $ownUserId,
            ]
        );
    }
}
