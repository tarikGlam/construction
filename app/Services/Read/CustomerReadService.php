<?php

namespace App\Services\Read;

use App\Services\ReceivableReconciliationService;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class CustomerReadService
{
    public function __construct(
        private ReceivableReconciliationService $receivableService
    ) {}

    private function resolveScope(AssistantAccessContext|WarehouseScope $context): array
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        return [$isRestricted, $allowedWarehouseIds, $ownUserId];
    }

    /**
     * Bounded list of customers with outstanding balances, strictly respecting warehouse scope.
     *
     * @return array{
     *     total_due: float,
     *     customer_count: int,
     *     rows: array<array{customer_id: int, name: string, due: float}>
     * }
     */
    public function dueList(AssistantAccessContext|WarehouseScope $context, int $limit = 10): array
    {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        // 1. Fast path: unassigned operational staff / empty warehouse access returns zero
        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'total_due' => 0.0,
                'customer_count' => 0,
                'rows' => [],
            ];
        }

        // 2. Fast path: own user access cannot view overall customer dues
        if ($ownUserId !== null) {
            return [
                'total_due' => 0.0,
                'customer_count' => 0,
                'rows' => [],
            ];
        }

        $boundedLimit = max(1, min($limit, 50));
        $warehouseIds = $isRestricted ? $allowedWarehouseIds : null;

        // Find candidate active customer IDs that could have non-zero balance
        $candidateIdsQuery = DB::table('sales')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', ReceivableReconciliationService::DRAFT_STATUS)
            ->whereNull('sales.voided_at')
            ->where(function ($q) {
                $q->whereNull('sales.accounting_status')
                    ->orWhereNotIn('sales.accounting_status', ['reversed', 'voided']);
            });

        if ($warehouseIds !== null) {
            $candidateIdsQuery->whereIn('sales.warehouse_id', $warehouseIds);
        }

        $candidateIds = $candidateIdsQuery->distinct()->pluck('sales.customer_id');

        if ($warehouseIds === null) {
            $openingIds = DB::table('customers')
                ->where('is_active', true)
                ->where('opening_balance', '>', 0)
                ->pluck('id');
            $candidateIds = $candidateIds->merge($openingIds);
        }

        $standaloneReturnCusts = DB::table('returns')
            ->whereNull('sale_id')
            ->where(function ($q) {
                $q->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            })
            ->when($warehouseIds !== null, fn($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->distinct()
            ->pluck('customer_id');
        $candidateIds = $candidateIds->merge($standaloneReturnCusts);

        $activeCustomerIds = DB::table('customers')
            ->where('is_active', true)
            ->whereIn('id', $candidateIds->filter()->unique())
            ->pluck('id')
            ->all();

        if (empty($activeCustomerIds)) {
            return [
                'total_due' => 0.0,
                'customer_count' => 0,
                'rows' => [],
            ];
        }

        // Delegate to authoritative operationalBalances()
        $balances = $this->receivableService->operationalBalances($activeCustomerIds, $warehouseIds);

        // Filter positive dues (> 0.0001)
        $dueBalances = [];
        foreach ($balances as $cId => $due) {
            if ($due > 0.0001) {
                $dueBalances[$cId] = $due;
            }
        }

        if (empty($dueBalances)) {
            return [
                'total_due' => 0.0,
                'customer_count' => 0,
                'rows' => [],
            ];
        }

        $totalDue = array_sum($dueBalances);
        $customerCount = count($dueBalances);

        // Sort descending by due balance
        arsort($dueBalances);

        $pagedCustomerIds = array_slice(array_keys($dueBalances), 0, $boundedLimit, true);
        $customerNames = DB::table('customers')
            ->whereIn('id', $pagedCustomerIds)
            ->pluck('name', 'id')
            ->all();

        $tableRows = [];
        foreach ($pagedCustomerIds as $cId) {
            $tableRows[] = [
                'customer_id' => (int) $cId,
                'name' => (string) ($customerNames[$cId] ?? 'Customer #' . $cId),
                'due' => round((float) $dueBalances[$cId], 2),
            ];
        }

        return [
            'total_due' => round($totalDue, 2),
            'customer_count' => $customerCount,
            'rows' => $tableRows,
        ];
    }

    /**
     * Get single customer operational balance.
     */
    public function balance(AssistantAccessContext|WarehouseScope $context, int $customerId): float
    {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return 0.0;
        }

        if ($ownUserId !== null) {
            return 0.0;
        }

        $warehouseIds = $isRestricted ? $allowedWarehouseIds : null;

        return round($this->receivableService->operationalBalance($customerId, null, $warehouseIds), 2);
    }

    /**
     * Recent purchase history of a customer.
     */
    public function purchaseHistory(
        AssistantAccessContext|WarehouseScope $context,
        int $customerId,
        int $limit = 10
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('sales')
            ->where('sales.customer_id', $customerId)
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', 3)
            ->whereNull('sales.voided_at')
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->select([
                'sales.id',
                'sales.reference_no',
                'sales.created_at',
                'sales.warehouse_id',
                'warehouses.name as warehouse_name',
                'sales.grand_total',
                'sales.paid_amount',
                'sales.payment_status',
                'sales.sale_status',
                'sales.item as item_count',
            ])
            ->orderBy('sales.created_at', 'desc')
            ->limit($boundedLimit);

        if ($isRestricted) {
            $query->whereIn('sales.warehouse_id', $allowedWarehouseIds);
        }

        if ($ownUserId !== null) {
            $query->where('sales.user_id', $ownUserId);
        }

        $results = [];
        foreach ($query->get() as $sale) {
            $grandTotal = (float) $sale->grand_total;
            $paidAmount = (float) $sale->paid_amount;
            $dueAmount = max(0.0, round($grandTotal - $paidAmount, 2));

            $paymentStatus = match ((int) $sale->payment_status) {
                1 => 'Pending / Due',
                2 => 'Due',
                3 => 'Partial',
                4 => 'Paid',
                default => 'Unknown',
            };

            $results[] = [
                'id' => (int) $sale->id,
                'reference_no' => (string) $sale->reference_no,
                'date' => substr((string) $sale->created_at, 0, 10),
                'warehouse' => (string) ($sale->warehouse_name ?? 'N/A'),
                'grand_total' => round($grandTotal, 2),
                'paid_amount' => round($paidAmount, 2),
                'due_amount' => $dueAmount,
                'status' => $paymentStatus,
                'item_count' => (int) $sale->item_count,
            ];
        }

        return $results;
    }

    /**
     * Customer profile summary with lifetime spend, balance, and recent orders.
     */
    public function customerProfile(
        AssistantAccessContext|WarehouseScope $context,
        int $customerId
    ): ?array {
        $customer = DB::table('customers')
            ->where('id', $customerId)
            ->where('is_active', true)
            ->first();

        if (!$customer) {
            return null;
        }

        $balance = $this->balance($context, $customerId);
        $recentPurchases = $this->purchaseHistory($context, $customerId, 5);

        // Compute lifetime purchases visible to user scope
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        $lifetimeQuery = DB::table('sales')
            ->where('sales.customer_id', $customerId)
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', 3);

        if ($isRestricted) {
            if (empty($allowedWarehouseIds)) {
                $lifetimeStats = (object) ['total_orders' => 0, 'lifetime_spend' => 0.0];
            } else {
                $lifetimeQuery->whereIn('sales.warehouse_id', $allowedWarehouseIds);
                if ($ownUserId !== null) {
                    $lifetimeQuery->where('sales.user_id', $ownUserId);
                }
                $lifetimeStats = $lifetimeQuery->selectRaw('COUNT(id) as total_orders, COALESCE(SUM(grand_total), 0) as lifetime_spend')->first();
            }
        } else {
            if ($ownUserId !== null) {
                $lifetimeQuery->where('sales.user_id', $ownUserId);
            }
            $lifetimeStats = $lifetimeQuery->selectRaw('COUNT(id) as total_orders, COALESCE(SUM(grand_total), 0) as lifetime_spend')->first();
        }

        return [
            'id' => (int) $customer->id,
            'name' => (string) $customer->name,
            'company_name' => (string) ($customer->company_name ?? ''),
            'email' => (string) ($customer->email ?? ''),
            'phone_number' => (string) ($customer->phone_number ?? ''),
            'address' => (string) ($customer->address ?? ''),
            'city' => (string) ($customer->city ?? ''),
            'outstanding_due' => round($balance, 2),
            'total_orders' => (int) ($lifetimeStats->total_orders ?? 0),
            'lifetime_spend' => round((float) ($lifetimeStats->lifetime_spend ?? 0.0), 2),
            'recent_purchases' => $recentPurchases,
        ];
    }
}
