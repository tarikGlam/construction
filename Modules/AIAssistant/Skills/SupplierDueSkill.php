<?php

namespace Modules\AIAssistant\Skills;

use App\Services\Read\SupplierReadService;
use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Modules\AIAssistant\DTO\WarehouseScope;

class SupplierDueSkill implements AssistantSkill
{
    private const RESULT_LIMIT = 10;

    public function __construct(
        private ?SupplierReadService $supplierReadService = null
    ) {
        $this->supplierReadService ??= app(SupplierReadService::class);
    }

    public function key(): string
    {
        return 'supplier_due';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_supplier_due_name');
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_supplier_due_description');
    }

    public function examples(): array
    {
        return [
            'supplier due summary',
            'show supplier dues',
            'which suppliers do we owe',
            'outstanding supplier balances',
            'accounts payable summary'
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
            return $this->buildResponse(0.0, 0, [], $scope->isRestricted, $scope->warehouseIds, $scope->ownUserId, true, 'empty_warehouse_scope');
        }

        if ($scope->ownUserId !== null) {
            return $this->buildResponse(0.0, 0, [], $scope->isRestricted, null, $scope->ownUserId, true, 'own_access_restriction');
        }

        $parsedParams = $context->businessContext['parsed_parameters'] ?? [];
        if (($parsedParams['entity_type'] ?? null) === 'supplier') {
            if (!empty($parsedParams['entity_not_found'])) {
                $searched = $parsedParams['entity_name'] ?? '';
                return new AssistantResponseData(
                    textSummary: "No active supplier found matching '{$searched}'.",
                    responseType: 'text',
                    metadata: [
                        'skill' => $this->key(),
                        'entity_type' => 'supplier',
                        'entity_found' => false,
                    ]
                );
            }

            if (!empty($parsedParams['entity_id'])) {
                $supplierId = (int) $parsedParams['entity_id'];
                $supplierName = $parsedParams['entity_name'] ?? ('Supplier #' . $supplierId);
                $balance = $this->supplierReadService->balance($scope, $supplierId);

                return new AssistantResponseData(
                    textSummary: "Outstanding balance owed to supplier {$supplierName} is " . number_format($balance, 2) . ".",
                    responseType: 'card',
                    cards: [
                        ['title' => "Supplier: {$supplierName}", 'value' => round($balance, 2)],
                    ],
                    table: [
                        'columns' => [__('db.ai_assistant_column_supplier_name'), __('db.ai_assistant_column_amount_due')],
                        'rows' => [
                            [$supplierName, round($balance, 2)],
                        ],
                    ],
                    links: [
                        ['label' => __('db.ai_assistant_link_view_suppliers'), 'url' => url('/supplier')]
                    ],
                    metadata: [
                        'skill' => $this->key(),
                        'supplier_id' => $supplierId,
                        'supplier_name' => $supplierName,
                        'due_balance' => round($balance, 2),
                    ]
                );
            }
        }

        $report = $this->supplierReadService->dueList($scope, self::RESULT_LIMIT);

        return $this->buildResponse(
            $report['total_due'],
            $report['supplier_count'],
            $report['rows'],
            $scope->isRestricted,
            $scope->warehouseIds,
            $scope->ownUserId
        );
    }

    private function buildResponse(float $totalDue, int $suppliersWithDue, array $tableRows, bool $isRestricted, mixed $warehouseIds, ?int $ownUserId = null, bool $failedClosed = false, ?string $reason = null): AssistantResponseData
    {
        $textSummary = $suppliersWithDue === 0
            ? __('db.ai_assistant_supplier_due_empty')
            : __('db.ai_assistant_supplier_due_summary', ['total' => number_format($totalDue, 2), 'count' => $suppliersWithDue]);

        $warnings = [];

        if ($failedClosed) {
            $textSummary = __('db.ai_assistant_supplier_due_forbidden');
            if ($reason === 'empty_warehouse_scope') {
                $warnings[] = __('db.ai_assistant_warning_no_warehouse');
            } else {
                $warnings[] = __('db.ai_assistant_warning_supplier_scope');
            }
        } elseif ($isRestricted) {
            $reason = 'warehouse_activity_only';
            $warnings[] = __('db.ai_assistant_warning_supplier_opening_balance');
        }

        $cards = [
            ['title' => __('db.ai_assistant_card_suppliers_with_due'), 'value' => $suppliersWithDue],
            ['title' => __('db.ai_assistant_card_total_payable'), 'value' => round($totalDue, 2)],
        ];

        $table = [];
        if (!empty($tableRows)) {
            $table = [
                'columns' => [__('db.ai_assistant_column_supplier_name'), __('db.ai_assistant_column_amount_payable')],
                'rows' => array_map(fn($r) => [
                    $r['name'],
                    $r['due'],
                ], $tableRows),
            ];
        }

        return new AssistantResponseData(
            textSummary: $textSummary,
            responseType: 'card',
            cards: $cards,
            table: $table,
            links: [
                ['label' => __('db.ai_assistant_link_view_supplier_report'), 'url' => url('/report/supplier_report')]
            ],
            warnings: $warnings,
            metadata: [
                'skill' => $this->key(),
                'result_limit' => self::RESULT_LIMIT,
                'failed_closed' => $failedClosed,
                'reason' => $reason,
                'warehouse_ids' => $warehouseIds,
                'own_user_id' => $ownUserId,
                'warehouse_scope_note' => 'Supplier due applies warehouse restrictions strictly on purchases, returns, and payments.',
            ]
        );
    }
}
