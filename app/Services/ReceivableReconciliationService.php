<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReceivableReconciliationService
{
    public const DRAFT_STATUS = 3;

    /**
     * Add the canonical return/refund aggregates used by every web A/R view.
     * Amounts intentionally use SalePro's stored ledger currency because the
     * posting service journals these same stored amounts without re-conversion.
     */
    public function joinBalanceComponents(Builder $query, string $salesAlias = 'sales'): Builder
    {
        $returns = DB::table('returns')
            ->selectRaw('sale_id, SUM(grand_total) as returned_amount')
            ->whereNotNull('sale_id')
            ->where(function (Builder $query) {
                $query->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            })
            ->groupBy('sale_id');

        $refunds = DB::table('payments')
            ->join('returns', 'returns.id', '=', 'payments.return_id')
            ->selectRaw('COALESCE(returns.sale_id, payments.sale_id) as sale_id, SUM(payments.amount) as refunded_amount')
            ->whereNotNull('return_id')
            ->where(function (Builder $query) {
                $query->whereNotNull('returns.sale_id')
                    ->orWhereNotNull('payments.sale_id');
            })
            ->where(function (Builder $query) {
                $query->whereNull('payments.accounting_status')
                    ->orWhereNotIn('payments.accounting_status', ['reversed', 'voided']);
            })
            ->groupByRaw('COALESCE(returns.sale_id, payments.sale_id)');

        return $query
            ->leftJoinSub($returns, 'ar_returns', 'ar_returns.sale_id', '=', "{$salesAlias}.id")
            ->leftJoinSub($refunds, 'ar_refunds', 'ar_refunds.sale_id', '=', "{$salesAlias}.id");
    }

    public function dueExpression(string $salesAlias = 'sales'): string
    {
        return "(COALESCE({$salesAlias}.grand_total, 0) - COALESCE({$salesAlias}.paid_amount, 0)"
            .' - COALESCE(ar_returns.returned_amount, 0)'
            .' + COALESCE(ar_refunds.refunded_amount, 0))';
    }

    public function salesQuery(?int $customerId = null, ?int $excludeSaleId = null, ?array $warehouseIds = null): Builder
    {
        $query = DB::table('sales')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', self::DRAFT_STATUS)
            ->whereNull('sales.voided_at')
            ->where(function (Builder $query) {
                $query->whereNull('sales.accounting_status')
                    ->orWhereNotIn('sales.accounting_status', ['reversed', 'voided']);
            })
            ->where(function (Builder $query) {
                $query->whereNull('sales.sale_type')
                    ->orWhereRaw('LOWER(sales.sale_type) <> ?', ['opening balance']);
            });

        if ($customerId !== null) {
            $query->where('sales.customer_id', $customerId);
        }
        if ($excludeSaleId !== null) {
            $query->where('sales.id', '!=', $excludeSaleId);
        }
        if ($warehouseIds !== null) {
            if (empty($warehouseIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('sales.warehouse_id', $warehouseIds);
            }
        }

        return $this->joinBalanceComponents($query);
    }

    /**
     * Receivable-bearing sales, including synthetic customer opening-balance sales.
     * Use this for payment allocation. Reporting salesQuery() intentionally excludes
     * opening balances so they are not presented as normal sales revenue/activity.
     */
    public function receivableSalesQuery(?int $customerId = null, ?array $warehouseIds = null): Builder
    {
        $query = DB::table('sales')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', self::DRAFT_STATUS)
            ->whereNull('sales.voided_at')
            ->where(function (Builder $query) {
                $query->whereNull('sales.accounting_status')
                    ->orWhereNotIn('sales.accounting_status', ['reversed', 'voided']);
            });

        if ($customerId !== null) {
            $query->where('sales.customer_id', $customerId);
        }
        if ($warehouseIds !== null) {
            if (empty($warehouseIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('sales.warehouse_id', $warehouseIds);
            }
        }

        return $this->joinBalanceComponents($query);
    }

    private function syntheticOpeningBalanceDue(?int $customerId = null, ?array $warehouseIds = null): float
    {
        return (float) $this->receivableSalesQuery($customerId, $warehouseIds)
            ->whereRaw("LOWER(COALESCE(sales.sale_type, '')) = ?", ['opening balance'])
            ->selectRaw('COALESCE(SUM('.$this->dueExpression().'), 0) as balance')
            ->value('balance');
    }

    private function hasSyntheticOpeningBalance(?int $customerId = null, ?array $warehouseIds = null): bool
    {
        return $this->receivableSalesQuery($customerId, $warehouseIds)
            ->whereRaw("LOWER(COALESCE(sales.sale_type, '')) = ?", ['opening balance'])
            ->exists();
    }

    public function operationalBalance(?int $customerId = null, ?int $excludeSaleId = null, ?array $warehouseIds = null): float
    {
        if ($customerId !== null && $excludeSaleId === null) {
            $balances = $this->operationalBalances([$customerId], $warehouseIds);
            return $balances[$customerId] ?? 0.0;
        }

        $sales = (float) $this->salesQuery($customerId, $excludeSaleId, $warehouseIds)
            ->selectRaw('COALESCE(SUM('.$this->dueExpression().'), 0) as balance')
            ->value('balance');

        // Modern SalePro stores customer opening balances both on the customer and as
        // a synthetic `Opening balance` sale. Use the synthetic sale's outstanding
        // amount when it exists so payments can settle it naturally. Fall back to the
        // customer field only for unrestricted legacy rows that have no synthetic sale.
        $hasSyntheticOpening = $this->hasSyntheticOpeningBalance($customerId, $warehouseIds);
        $opening = $hasSyntheticOpening
            ? $this->syntheticOpeningBalanceDue($customerId, $warehouseIds)
            : ($warehouseIds === null
                ? (float) DB::table('customers')
                ->when($customerId !== null, fn (Builder $query) => $query->where('id', $customerId))
                ->sum('opening_balance')
                : 0.0);

        // Older SalePro returns can be customer-linked without a sale_id. They
        // cannot be allocated to an individual sale report, but they remain a
        // real customer receivable adjustment and must not disappear from the
        // customer/credit-limit balance.
        $standaloneReturns = DB::table('returns')
            ->whereNull('sale_id')
            ->where(function (Builder $query) {
                $query->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            })
            ->when($customerId !== null, fn (Builder $query) => $query->where('customer_id', $customerId))
            ->when($warehouseIds !== null, function (Builder $query) use ($warehouseIds) {
                if (empty($warehouseIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('warehouse_id', $warehouseIds);
                }
            });
        $standaloneReturned = (float) (clone $standaloneReturns)->sum('grand_total');

        $standaloneRefunded = (float) DB::table('payments')
            ->join('returns', 'returns.id', '=', 'payments.return_id')
            ->whereNull('returns.sale_id')
            ->where(function (Builder $query) {
                $query->whereNull('payments.accounting_status')
                    ->orWhereNotIn('payments.accounting_status', ['reversed', 'voided']);
            })
            ->when($customerId !== null, fn (Builder $query) => $query->where('returns.customer_id', $customerId))
            ->when($warehouseIds !== null, function (Builder $query) use ($warehouseIds) {
                if (empty($warehouseIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('returns.warehouse_id', $warehouseIds);
                }
            })
            ->sum('payments.amount');

        return round($sales + $opening - $standaloneReturned + $standaloneRefunded, 4);
    }

    /**
     * Batch calculation of operational due balances for multiple customers.
     * Returns an associative array of [customer_id => due_balance].
     *
     * @param int[] $customerIds
     * @param array<int>|null $warehouseIds
     * @return array<int, float>
     */
    public function operationalBalances(array $customerIds = [], ?array $warehouseIds = null): array
    {
        if (empty($customerIds)) {
            return [];
        }

        // 1. Sales balance per customer
        $salesBalances = $this->salesQuery(null, null, $warehouseIds)
            ->whereIn('sales.customer_id', $customerIds)
            ->selectRaw('sales.customer_id, COALESCE(SUM('.$this->dueExpression().'), 0) as balance')
            ->groupBy('sales.customer_id')
            ->pluck('balance', 'customer_id')
            ->all();

        // 2. Opening balance due per customer. Prefer the synthetic opening-balance
        // sale when present so receipts against it reduce the displayed receivable.
        // Legacy customer-level openings are omitted for warehouse-scoped requests.
        $openingSaleBalances = $this->receivableSalesQuery(null, $warehouseIds)
            ->whereIn('sales.customer_id', $customerIds)
            ->whereRaw("LOWER(COALESCE(sales.sale_type, '')) = ?", ['opening balance'])
            ->selectRaw('sales.customer_id, COALESCE(SUM('.$this->dueExpression().'), 0) as balance')
            ->groupBy('sales.customer_id')
            ->pluck('balance', 'customer_id')
            ->all();

        $openingSaleCustomers = $this->receivableSalesQuery(null, $warehouseIds)
            ->whereIn('sales.customer_id', $customerIds)
            ->whereRaw("LOWER(COALESCE(sales.sale_type, '')) = ?", ['opening balance'])
            ->distinct()
            ->pluck('sales.customer_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $legacyOpeningBalances = [];
        if ($warehouseIds === null) {
            $legacyOpeningBalances = DB::table('customers')
                ->whereIn('id', $customerIds)
                ->pluck('opening_balance', 'id')
                ->all();
        }

        // 3. Standalone returns per customer
        $standaloneReturns = DB::table('returns')
            ->whereNull('sale_id')
            ->whereIn('customer_id', $customerIds)
            ->where(function (Builder $query) {
                $query->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            })
            ->when($warehouseIds !== null, function (Builder $query) use ($warehouseIds) {
                if (empty($warehouseIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('warehouse_id', $warehouseIds);
                }
            })
            ->selectRaw('customer_id, COALESCE(SUM(grand_total), 0) as returned')
            ->groupBy('customer_id')
            ->pluck('returned', 'customer_id')
            ->all();

        // 4. Standalone refunds per customer
        $standaloneRefunds = DB::table('payments')
            ->join('returns', 'returns.id', '=', 'payments.return_id')
            ->whereNull('returns.sale_id')
            ->whereIn('returns.customer_id', $customerIds)
            ->where(function (Builder $query) {
                $query->whereNull('payments.accounting_status')
                    ->orWhereNotIn('payments.accounting_status', ['reversed', 'voided']);
            })
            ->when($warehouseIds !== null, function (Builder $query) use ($warehouseIds) {
                if (empty($warehouseIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('returns.warehouse_id', $warehouseIds);
                }
            })
            ->selectRaw('returns.customer_id, COALESCE(SUM(payments.amount), 0) as refunded')
            ->groupBy('returns.customer_id')
            ->pluck('refunded', 'customer_id')
            ->all();

        $result = [];
        foreach ($customerIds as $id) {
            $s = (float) ($salesBalances[$id] ?? 0.0);
            $o = isset($openingSaleCustomers[(int) $id])
                ? (float) ($openingSaleBalances[$id] ?? 0.0)
                : (float) ($legacyOpeningBalances[$id] ?? 0.0);
            $ret = (float) ($standaloneReturns[$id] ?? 0.0);
            $ref = (float) ($standaloneRefunds[$id] ?? 0.0);

            $result[$id] = round($s + $o - $ret + $ref, 4);
        }

        return $result;
    }
}
