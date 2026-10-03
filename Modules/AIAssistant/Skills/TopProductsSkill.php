<?php

namespace Modules\AIAssistant\Skills;

use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use App\Services\Read\SalesReadService;

class TopProductsSkill implements AssistantSkill
{
    /** Maximum number of products returned in the response table. */
    private const RESULT_LIMIT = 10;

    private SalesReadService $salesReadService;

    public function __construct(?SalesReadService $salesReadService = null)
    {
        $this->salesReadService = $salesReadService ?? app(SalesReadService::class);
    }

    public function key(): string
    {
        return 'top_products';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_top_products_name');
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_top_products_description');
    }

    public function examples(): array
    {
        return [
            'show top selling products',
            'top products',
        ];
    }

    public function canHandle(AssistantMessageData $message): bool
    {
        return \Modules\AIAssistant\Services\LocalizedIntentMatcher::matchesSkill($this->key(), $message->content);
    }

    public function handle(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
    {
        $scope = \Modules\AIAssistant\DTO\WarehouseScope::fromContext($context);

        // Fast path: explicitly empty warehouse restriction returns empty
        if ($scope->isRestricted && empty($scope->warehouseIds)) {
            return $this->buildResponse([], $scope->warehouseIds);
        }

        $parsedParams = $context->businessContext['parsed_parameters'] ?? [];
        $limit = isset($parsedParams['limit']) && is_numeric($parsedParams['limit'])
            ? min(50, max(1, (int) $parsedParams['limit']))
            : self::RESULT_LIMIT;

        $date = $parsedParams['date_range']['start_date'] ?? Carbon::today()->toDateString();
        $tableRows = $this->salesReadService->topProductsReport($scope, $date, $limit);

        return $this->buildResponse($tableRows, $scope->isRestricted ? $scope->warehouseIds : null, $scope->ownUserId);
    }

    private function buildResponse(array $tableRows, ?array $warehouseIds, ?int $ownUserId = null): AssistantResponseData
    {
        $productCount = count($tableRows);

        $textSummary = $productCount === 0
            ? __('db.ai_assistant_top_products_empty')
            : __('db.ai_assistant_top_products_summary', ['count' => $productCount]);

        $cards = [
            ['title' => __('db.ai_assistant_card_products_sold_today'), 'value' => $productCount],
        ];

        $table = [];
        if (!empty($tableRows)) {
            $table = [
                'columns' => [__('db.ai_assistant_column_product'), __('db.ai_assistant_column_qty_sold'), __('db.ai_assistant_column_sales_value')],
                'rows' => array_map(fn($r) => [
                    $r['product'],
                    $r['qty_sold'],
                    $r['sales_value'],
                ], $tableRows),
            ];
        }

        return new AssistantResponseData(
            textSummary: $textSummary,
            responseType: 'card',
            cards: $cards,
            table: $table,
            links: [
                ['label' => __('db.ai_assistant_link_view_all_sales'), 'url' => url('/sales')]
            ],
            metadata: [
                'skill' => $this->key(),
                'metric_type' => 'gross_sales',
                'date' => Carbon::today()->toDateString(),
                'result_limit' => self::RESULT_LIMIT,
                'warehouse_ids' => $warehouseIds,
                'own_user_id' => $ownUserId,
            ]
        );
    }
}
