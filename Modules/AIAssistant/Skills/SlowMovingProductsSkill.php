<?php

namespace Modules\AIAssistant\Skills;

use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;

use App\Services\Read\InventoryReadService;

class SlowMovingProductsSkill implements AssistantSkill
{
    private const RESULT_LIMIT = 10;
    private const LOOKBACK_DAYS = 30;

    private InventoryReadService $inventoryReadService;

    public function __construct(?InventoryReadService $inventoryReadService = null)
    {
        $this->inventoryReadService = $inventoryReadService ?? app(InventoryReadService::class);
    }

    public function key(): string
    {
        return 'slow_moving_products';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_slow_products_name');
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_slow_products_description', ['days' => self::LOOKBACK_DAYS]);
    }

    public function examples(): array
    {
        return [
            'show slow moving products',
            'slow moving products',
            'products not selling',
            'products with low sales',
            'dead stock'
        ];
    }

    public function canHandle(AssistantMessageData $message): bool
    {
        return \Modules\AIAssistant\Services\LocalizedIntentMatcher::matchesSkill($this->key(), $message->content);
    }

    public function handle(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
    {
        $scope = WarehouseScope::fromContext($context);

        // Fast path: explicitly empty warehouse restriction returns empty
        if ($scope->isRestricted && empty($scope->warehouseIds)) {
            return $this->buildResponse(0, [], $scope->isRestricted, $scope->warehouseIds, $scope->ownUserId, true, 'empty_warehouse_scope');
        }

        // Fast path: own access cannot safely view global slow moving products as inventory is global
        if ($scope->ownUserId !== null) {
            return $this->buildResponse(0, [], $scope->isRestricted, null, $scope->ownUserId, true, 'own_access_restriction');
        }

        $report = $this->inventoryReadService->slowMovingProductsReport($scope, self::LOOKBACK_DAYS, self::RESULT_LIMIT);

        return $this->buildResponse($report['total_count'], $report['rows'], $scope->isRestricted, $scope->warehouseIds, $scope->ownUserId);
    }

    private function buildResponse(int $totalSlowProducts, array $tableRows, bool $isRestricted, mixed $warehouseIds, ?int $ownUserId = null, bool $failedClosed = false, ?string $reason = null): AssistantResponseData
    {
        $textSummary = $totalSlowProducts === 0
            ? __('db.ai_assistant_slow_products_empty', ['days' => self::LOOKBACK_DAYS])
            : __('db.ai_assistant_slow_products_summary', ['count' => $totalSlowProducts, 'days' => self::LOOKBACK_DAYS]);

        $warnings = [];
        if ($failedClosed) {
            $textSummary = __('db.ai_assistant_slow_products_forbidden');
            if ($reason === 'empty_warehouse_scope') {
                $warnings[] = __('db.ai_assistant_warning_no_warehouse');
            } else {
                $warnings[] = __('db.ai_assistant_warning_slow_products_scope');
            }
        }

        $cards = [
            ['title' => __('db.ai_assistant_card_slow_products'), 'value' => $totalSlowProducts],
            ['title' => __('db.ai_assistant_card_lookback_period'), 'value' => __('db.ai_assistant_days', ['count' => self::LOOKBACK_DAYS])],
        ];

        $table = [];
        if (!empty($tableRows)) {
            $table = [
                'columns' => [__('db.ai_assistant_column_product_code'), __('db.ai_assistant_column_current_stock'), __('db.ai_assistant_column_sold_period', ['days' => self::LOOKBACK_DAYS]), __('db.ai_assistant_column_last_sale')],
                'rows' => array_map(fn($r) => [
                    $r['name_code'],
                    $r['stock'],
                    $r['sales'],
                    $r['last_sale'],
                ], $tableRows),
            ];
        }

        return new AssistantResponseData(
            textSummary: $textSummary,
            responseType: 'card',
            cards: $cards,
            table: $table,
            links: [
                ['label' => __('db.ai_assistant_link_view_quantity_alert'), 'url' => route('report.qtyAlert')]
            ],
            warnings: $warnings,
            metadata: [
                'skill' => $this->key(),
                'metric_type' => 'no_sales_in_lookback_period',
                'metric_definition' => 'Products with positive stock and zero net sales in the lookback period',
                'result_limit' => self::RESULT_LIMIT,
                'failed_closed' => $failedClosed,
                'reason' => $reason,
                'lookback_days' => self::LOOKBACK_DAYS,
                'warehouse_ids' => $warehouseIds,
                'own_user_id' => $ownUserId,
                'warehouse_scope_note' => 'Applies strict warehouse boundaries when restricted.',
            ]
        );
    }
}
