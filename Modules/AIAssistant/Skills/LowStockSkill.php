<?php

namespace Modules\AIAssistant\Skills;

use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Illuminate\Support\Facades\DB;

use App\Services\Read\InventoryReadService;

class LowStockSkill implements AssistantSkill
{
    /** Maximum number of products returned in the response table. */
    private const RESULT_LIMIT = 15;

    private InventoryReadService $inventoryReadService;

    public function __construct(?InventoryReadService $inventoryReadService = null)
    {
        $this->inventoryReadService = $inventoryReadService ?? app(InventoryReadService::class);
    }

    public function key(): string
    {
        return 'low_stock';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_low_stock_name');
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_low_stock_description');
    }

    public function examples(): array
    {
        return [
            'show low stock products',
            'stock alerts',
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
            return $this->buildResponse(0, [], $scope->warehouseIds);
        }

        // Fast path: own access cannot view global stock, fail closed
        if ($scope->ownUserId !== null) {
            return $this->buildResponse(0, [], null, $scope->ownUserId);
        }

        $report = $this->inventoryReadService->lowStockReport($scope, self::RESULT_LIMIT);

        return $this->buildResponse($report['total_count'], $report['rows'], $scope->isRestricted ? $scope->warehouseIds : null, $scope->ownUserId);
    }

    private function buildResponse(int $count, array $tableRows, ?array $warehouseIds, ?int $ownUserId = null): AssistantResponseData
    {
        $textSummary = $count === 0
            ? __('db.ai_assistant_low_stock_empty')
            : __('db.ai_assistant_low_stock_summary', ['count' => $count]);

        $cards = [
            ['title' => __('db.ai_assistant_card_low_stock_products'), 'value' => $count],
        ];

        $table = [];
        if (!empty($tableRows)) {
            $table = [
                'columns' => [__('db.ai_assistant_column_product'), __('db.ai_assistant_column_warehouse_id'), __('db.ai_assistant_column_current_qty'), __('db.ai_assistant_column_alert_qty')],
                'rows' => array_map(fn($r) => [
                    $r['product'],
                    $r['warehouse_id'],
                    $r['current_qty'],
                    $r['alert_qty'],
                ], $tableRows),
            ];
        }

        return new AssistantResponseData(
            textSummary: $textSummary,
            responseType: 'card',
            cards: $cards,
            table: $table,
            links: [
                ['label' => __('db.ai_assistant_link_view_stock_alert'), 'url' => route('report.qtyAlert')]
            ],
            metadata: [
                'skill' => $this->key(),
                'result_limit' => self::RESULT_LIMIT,
                'warehouse_ids' => $warehouseIds,
                'own_user_id' => $ownUserId,
            ]
        );
    }
}
