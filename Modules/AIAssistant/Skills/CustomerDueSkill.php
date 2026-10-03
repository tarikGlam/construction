<?php

namespace Modules\AIAssistant\Skills;

use App\Services\Read\CustomerReadService;
use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class CustomerDueSkill implements AssistantSkill
{
    /** Maximum number of customers in the response table. */
    private const RESULT_LIMIT = 10;

    public function __construct(
        private ?CustomerReadService $customerReadService = null
    ) {
        $this->customerReadService ??= app(CustomerReadService::class);
    }

    public function key(): string
    {
        return 'customer_due';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_customer_due_name');
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_customer_due_description');
    }

    public function examples(): array
    {
        return [
            'customer due summary',
            'show customer dues',
        ];
    }

    public function canHandle(AssistantMessageData $message): bool
    {
        return \Modules\AIAssistant\Services\LocalizedIntentMatcher::matchesSkill($this->key(), $message->content);
    }

    /**
     * Authoritative customer due report scoped by warehouse and user permissions.
     * Uses CustomerReadService which aligns with CustomerDueReportService canonical logic.
     */
    public function handle(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
    {
        $accessContext = $context->resolveAccessContext();
        $scope = $context->resolveWarehouseScope();

        // Fast path: explicitly empty warehouse restriction returns empty
        if ($scope->isRestricted && empty($scope->warehouseIds)) {
            return $this->buildResponse(0.0, 0, [], $scope->warehouseIds);
        }

        // Fast path: own access cannot view global customer debt, fail closed
        if ($scope->ownUserId !== null) {
            return $this->buildResponse(0.0, 0, [], null, $scope->ownUserId);
        }

        $parsedParams = $context->businessContext['parsed_parameters'] ?? [];
        if (($parsedParams['entity_type'] ?? null) === 'customer') {
            if (!empty($parsedParams['entity_not_found'])) {
                $searched = $parsedParams['entity_name'] ?? '';
                return new AssistantResponseData(
                    textSummary: "No active customer found matching '{$searched}'.",
                    responseType: 'text',
                    metadata: [
                        'skill' => $this->key(),
                        'entity_type' => 'customer',
                        'entity_found' => false,
                    ]
                );
            }

            if (!empty($parsedParams['entity_id'])) {
                $customerId = (int) $parsedParams['entity_id'];
                $customerName = $parsedParams['entity_name'] ?? ('Customer #' . $customerId);
                $balance = $this->customerReadService->balance($scope, $customerId);

                return new AssistantResponseData(
                    textSummary: "Outstanding balance for customer {$customerName} is " . number_format($balance, 2) . ".",
                    responseType: 'card',
                    cards: [
                        ['title' => "Customer: {$customerName}", 'value' => round($balance, 2)],
                    ],
                    table: [
                        'columns' => [__('db.ai_assistant_column_customer_name'), __('db.ai_assistant_column_amount_due')],
                        'rows' => [
                            [$customerName, round($balance, 2)],
                        ],
                    ],
                    links: [
                        ['label' => __('db.ai_assistant_link_view_customers'), 'url' => url('/customer')]
                    ],
                    metadata: [
                        'skill' => $this->key(),
                        'customer_id' => $customerId,
                        'customer_name' => $customerName,
                        'due_balance' => round($balance, 2),
                    ]
                );
            }
        }

        $report = $this->customerReadService->dueList($scope, self::RESULT_LIMIT);

        return $this->buildResponse(
            $report['total_due'],
            $report['customer_count'],
            $report['rows'],
            $scope->isRestricted ? $scope->warehouseIds : null,
            $scope->ownUserId
        );
    }

    private function buildResponse(float $totalDue, int $customersWithDue, array $tableRows, mixed $warehouseIds, ?int $ownUserId = null): AssistantResponseData
    {
        $textSummary = $customersWithDue === 0
            ? __('db.ai_assistant_customer_due_empty')
            : __('db.ai_assistant_customer_due_summary', ['count' => $customersWithDue, 'total' => number_format($totalDue, 2)]);
            
        if ($ownUserId !== null) {
            $textSummary = __('db.ai_assistant_customer_due_forbidden');
        }

        $cards = [
            ['title' => __('db.ai_assistant_card_customers_with_due'), 'value' => $customersWithDue],
            ['title' => __('db.ai_assistant_card_total_outstanding'), 'value' => round($totalDue, 2)],
        ];

        $table = [];
        if (!empty($tableRows)) {
            $table = [
                'columns' => [__('db.ai_assistant_column_customer_name'), __('db.ai_assistant_column_amount_due')],
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
                ['label' => __('db.ai_assistant_link_view_customers'), 'url' => url('/customer')]
            ],
            metadata: [
                'skill' => $this->key(),
                'result_limit' => self::RESULT_LIMIT,
                'warehouse_ids' => $warehouseIds,
                'own_user_id' => $ownUserId,
                'warehouse_scope_note' => 'Customer due respects warehouse restrictions on sales, returns, and payments.',
            ]
        );
    }
}
