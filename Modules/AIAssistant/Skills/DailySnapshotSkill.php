<?php

namespace Modules\AIAssistant\Skills;

use App\Services\Read\ExpenseReadService;
use App\Services\Read\InventoryReadService;
use App\Services\Read\PurchaseReadService;
use App\Services\Read\SalesReadService;
use Carbon\Carbon;
use Modules\AIAssistant\Contracts\AssistantSkill;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantResponseData;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Services\LocalizedIntentMatcher;

class DailySnapshotSkill implements AssistantSkill
{
    public function __construct(
        private ?SalesReadService $salesReadService = null,
        private ?PurchaseReadService $purchaseReadService = null,
        private ?ExpenseReadService $expenseReadService = null,
        private ?InventoryReadService $inventoryReadService = null
    ) {
        $this->salesReadService ??= app(SalesReadService::class);
        $this->purchaseReadService ??= app(PurchaseReadService::class);
        $this->expenseReadService ??= app(ExpenseReadService::class);
        $this->inventoryReadService ??= app(InventoryReadService::class);
    }

    public function key(): string
    {
        return 'daily_snapshot';
    }

    public function name(): string
    {
        return __('db.ai_assistant_skill_daily_snapshot_name');
    }

    public function description(): string
    {
        return __('db.ai_assistant_skill_daily_snapshot_description');
    }

    public function examples(): array
    {
        return [
            'daily business snapshot',
            'today\'s business summary',
            'show today\'s snapshot',
            'how is business today',
            'daily snapshot'
        ];
    }

    public function canHandle(AssistantMessageData $message): bool
    {
        return LocalizedIntentMatcher::matchesSkill($this->key(), $message->content);
    }

    public function handle(AssistantMessageData $message, AssistantContextData $context): AssistantResponseData
    {
        $accessContext = $context->resolveAccessContext();
        $scope = WarehouseScope::fromContext($context);

        // Check component permissions independently
        $canSales = $accessContext->hasPermission('sales-index');
        $canPurchases = $accessContext->hasPermission('purchases-index');
        $canExpenses = $accessContext->hasPermission('expenses-index');
        $canInventory = $accessContext->hasPermission('products-index');

        $warnings = [];
        $failedClosed = false;
        $reason = null;

        // 1. Sales Component
        if ($canSales) {
            $salesSummary = $this->salesReadService->todaySummary($scope);
            $salesTotal = (float) ($salesSummary['total_sales'] ?? 0.0);
            $salesDue = (float) ($salesSummary['due_amount'] ?? 0.0);
            $salesCount = (int) ($salesSummary['total_orders'] ?? 0);
        } else {
            $salesTotal = 'Unavailable';
            $salesDue = 'Unavailable';
            $salesCount = null;
        }

        // 2. Purchase Component
        if ($canPurchases) {
            $purchaseSummary = $this->purchaseReadService->todaySummary($scope);
            $purchaseTotal = (float) ($purchaseSummary['total_purchases'] ?? 0.0);
            $purchaseDue = (float) ($purchaseSummary['due_amount'] ?? 0.0);
            $purchaseCount = (int) ($purchaseSummary['total_orders'] ?? 0);
        } else {
            $purchaseTotal = 'Unavailable';
            $purchaseDue = 'Unavailable';
            $purchaseCount = null;
        }

        // 3. Expense Component
        if ($canExpenses) {
            $expenseSummary = $this->expenseReadService->todaySummary($scope);
            $expenseTotal = (float) ($expenseSummary['total_expenses'] ?? 0.0);
            $expenseCount = (int) ($expenseSummary['expense_count'] ?? 0);
        } else {
            $expenseTotal = 'Unavailable';
            $expenseCount = null;
        }

        // Calculate total transactions from permitted components
        $knownCounts = array_filter([$salesCount, $purchaseCount, $expenseCount], fn($c) => $c !== null);
        $totalTransactions = !empty($knownCounts) ? array_sum($knownCounts) : 'Unavailable';

        // 4. Low Stock Component
        $lowStockValue = null;
        $includeLowStockCard = true;

        if ($canInventory) {
            if ($scope->ownUserId !== null) {
                $warnings[] = __('db.ai_assistant_warning_low_stock_scope');
                $reason = 'partial_own_access_restriction';
                $includeLowStockCard = false;
            } else {
                $lowStockReport = $this->inventoryReadService->lowStockReport($scope, 15);
                $lowStockValue = (int) ($lowStockReport['total_count'] ?? 0);
            }
        } else {
            $lowStockValue = 'Unavailable';
        }

        // Build Cards
        $cards = [
            ['title' => __('db.ai_assistant_card_todays_sales'), 'value' => $salesTotal],
            ['title' => __('db.ai_assistant_card_todays_purchases'), 'value' => $purchaseTotal],
            ['title' => __('db.ai_assistant_card_todays_expenses'), 'value' => $expenseTotal],
            ['title' => __('db.ai_assistant_card_sales_due_created'), 'value' => $salesDue],
            ['title' => __('db.ai_assistant_card_purchases_due_created'), 'value' => $purchaseDue],
            ['title' => __('db.ai_assistant_card_total_transactions'), 'value' => $totalTransactions],
        ];

        if ($includeLowStockCard) {
            $cards[] = ['title' => __('db.ai_assistant_card_low_stock_items'), 'value' => $lowStockValue];
        }

        // Empty warehouse scope check
        if ($scope->isRestricted && empty($scope->warehouseIds)) {
            $warnings[] = __('db.ai_assistant_warning_no_warehouse');
            $failedClosed = true;
            $reason = 'empty_warehouse_scope';
            foreach ($cards as &$card) {
                if (is_numeric($card['value'])) {
                    $card['value'] = 0;
                }
            }
            unset($card);
        }

        // Missing permissions warning
        $unpermitted = [];
        if (!$canSales) $unpermitted[] = 'sales';
        if (!$canPurchases) $unpermitted[] = 'purchases';
        if (!$canExpenses) $unpermitted[] = 'expenses';
        if (!$canInventory) $unpermitted[] = 'inventory';

        if (!empty($unpermitted)) {
            $warnings[] = 'Some metrics are marked Unavailable because your role lacks permission: ' . implode(', ', $unpermitted) . '.';
        }

        // Format summary text
        $formatVal = fn($val) => is_numeric($val) ? number_format((float) $val, 2) : 'Unavailable';
        $textSummary = __('db.ai_assistant_daily_snapshot_summary', [
            'sales' => $formatVal($salesTotal),
            'purchases' => $formatVal($purchaseTotal),
            'expenses' => $formatVal($expenseTotal),
        ]);

        // Links filtered by permission
        $links = [];
        if ($canSales) {
            $links[] = ['label' => __('db.ai_assistant_link_view_sales'), 'url' => url('/sales')];
        }
        if ($canPurchases) {
            $links[] = ['label' => __('db.ai_assistant_link_view_purchases'), 'url' => url('/purchases')];
        }
        if ($canExpenses) {
            $links[] = ['label' => __('db.ai_assistant_link_view_expenses'), 'url' => url('/expenses')];
        }

        // Follow-up suggestions filtered by permission
        $salesName = config('aiassistant.specialists.sales.name', 'Jason');
        $supplyName = config('aiassistant.specialists.supply.name', 'Abdul');
        $financeName = config('aiassistant.specialists.finance.name', 'Nora');
        $coordinatorName = config('aiassistant.specialists.business.name', 'Salama');

        $followUps = [];
        if ($canSales) {
            $followUps[] = ['specialist' => 'sales', 'label' => "Ask {$salesName} for Today's Sales", 'prompt' => 'today sales'];
        }
        if ($canInventory) {
            $followUps[] = ['specialist' => 'supply', 'label' => "Ask {$supplyName} for Low Stock Alerts", 'prompt' => 'low stock'];
        }
        if ($accessContext->hasPermission('account-index')) {
            $followUps[] = ['specialist' => 'finance', 'label' => "Ask {$financeName} for Cash & Bank Balances", 'prompt' => 'cash and bank summary'];
        }

        return new AssistantResponseData(
            textSummary: $textSummary,
            responseType: 'card',
            cards: $cards,
            table: [],
            links: $links,
            warnings: $warnings,
            metadata: [
                'skill' => $this->key(),
                'date' => Carbon::today()->format('Y-m-d'),
                'failed_closed' => $failedClosed,
                'reason' => $reason,
                'warehouse_ids' => $scope->isRestricted ? $scope->warehouseIds : null,
                'own_user_id' => $scope->ownUserId,
                'component_permissions' => [
                    'sales' => $canSales,
                    'purchases' => $canPurchases,
                    'expenses' => $canExpenses,
                    'inventory' => $canInventory,
                ],
                'coordinator' => $coordinatorName,
                'specialist' => 'business',
                'follow_up_suggestions' => $followUps,
            ]
        );
    }
}
