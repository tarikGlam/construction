<?php

namespace App\Services\Read;

use Modules\AIAssistant\Security\AssistantAccessContext;

class BusinessSummaryReadService
{
    public function __construct(
        private SalesReadService $salesReadService,
        private PurchaseReadService $purchaseReadService,
        private InventoryReadService $inventoryReadService,
        private CustomerReadService $customerReadService,
        private SupplierReadService $supplierReadService,
    ) {}

    /**
     * Composed executive snapshot for today.
     *
     * @return array{
     *     sales: array,
     *     purchases: array,
     *     customer_due: array,
     *     supplier_due: array,
     *     low_stock_count: int
     * }
     */
    public function todaySnapshot(AssistantAccessContext $context): array
    {
        $today = now()->toDateString();

        $sales = $this->salesReadService->summary($context, $today, $today);
        $purchases = $this->purchaseReadService->summary($context, $today, $today);
        $customerDue = $this->customerReadService->dueList($context, 5);
        $supplierDue = $this->supplierReadService->dueList($context, 5);
        $lowStock = $this->inventoryReadService->lowStock($context, 100);

        return [
            'sales' => $sales,
            'purchases' => $purchases,
            'customer_due' => $customerDue,
            'supplier_due' => $supplierDue,
            'low_stock_count' => count($lowStock),
        ];
    }

    /**
     * Composed period summary.
     */
    public function periodSummary(AssistantAccessContext $context, string $startDate, string $endDate): array
    {
        $sales = $this->salesReadService->summary($context, $startDate, $endDate);
        $purchases = $this->purchaseReadService->summary($context, $startDate, $endDate);

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'sales' => $sales,
            'purchases' => $purchases,
        ];
    }
}
