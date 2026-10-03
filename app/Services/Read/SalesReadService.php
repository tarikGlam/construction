<?php

namespace App\Services\Read;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class SalesReadService
{
    public const DRAFT_STATUS = 3;

    /**
     * Resolve authorization scope.
     *
     * @return array{0: bool, 1: array<int>, 2: int|null}
     */
    private function resolveScope(AssistantAccessContext|WarehouseScope $context): array
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        return [$isRestricted, $allowedWarehouseIds, $ownUserId];
    }

    /**
     * Apply warehouse and own-user authorization boundaries to any sales query.
     */
    public function applyScope(
        $query,
        AssistantAccessContext|WarehouseScope $context,
        string $warehouseColumn = 'sales.warehouse_id',
        string $userColumn = 'sales.user_id'
    ) {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted) {
            if (empty($allowedWarehouseIds)) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereIn($warehouseColumn, $allowedWarehouseIds);
        }

        if ($ownUserId !== null) {
            $query->where($userColumn, $ownUserId);
        }

        return $query;
    }

    /**
     * Apply authoritative active lifecycle constraints to any sales query.
     * Ensures draft, deleted, voided, reversed, and opening balance sales are excluded.
     */
    public function applyActiveLifecycle($query, string $salesTable = 'sales')
    {
        return $query
            ->whereNull("{$salesTable}.deleted_at")
            ->where("{$salesTable}.sale_status", '!=', self::DRAFT_STATUS)
            ->whereNull("{$salesTable}.voided_at")
            ->where(function ($q) use ($salesTable) {
                $q->whereNull("{$salesTable}.accounting_status")
                    ->orWhereNotIn("{$salesTable}.accounting_status", ['reversed', 'voided']);
            })
            ->where(function ($q) use ($salesTable) {
                $q->whereNull("{$salesTable}.sale_type")
                    ->orWhereRaw("LOWER({$salesTable}.sale_type) <> ?", ['opening balance']);
            });
    }

    /**
     * Base query for active, non-draft, non-voided sales.
     */
    public function baseQuery(AssistantAccessContext|WarehouseScope $context, string $salesTable = 'sales'): \Illuminate\Database\Query\Builder
    {
        $query = DB::table($salesTable);
        $this->applyActiveLifecycle($query, $salesTable);

        return $this->applyScope($query, $context);
    }

    /**
     * Aggregate sales summary for a given period and context.
     *
     * @return array{
     *     total_sales: float,
     *     paid_amount: float,
     *     due_amount: float,
     *     total_orders: int,
     *     total_items: int,
     *     returned_amount: float,
     *     net_sales: float
     * }
     */
    public function summary(
        AssistantAccessContext|WarehouseScope $context,
        ?string $startDate = null,
        ?string $endDate = null,
        array $filters = []
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'total_sales' => 0.0,
                'paid_amount' => 0.0,
                'due_amount' => 0.0,
                'total_orders' => 0,
                'total_items' => 0,
                'returned_amount' => 0.0,
                'net_sales' => 0.0,
            ];
        }

        $query = $this->baseQuery($context);

        if ($startDate !== null) {
            $query->whereDate('sales.created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('sales.created_at', '<=', $endDate);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('sales.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('sales.customer_id', (int) $filters['customer_id']);
        }

        $row = $query->selectRaw('
            COALESCE(SUM(sales.grand_total), 0) as total_sales,
            COALESCE(SUM(sales.paid_amount), 0) as paid_amount,
            COUNT(sales.id) as total_orders,
            COALESCE(SUM(sales.total_qty), 0) as total_items,
            COALESCE(SUM(CASE WHEN sales.grand_total > sales.paid_amount THEN sales.grand_total - sales.paid_amount ELSE 0 END), 0) as due_amount
        ')->first();

        // Calculate returns for the same period and scope
        $returnsQuery = DB::table('returns')
            ->where(function ($q) {
                $q->whereNull('accounting_status')
                    ->orWhereNotIn('accounting_status', ['reversed', 'voided']);
            });

        if ($startDate !== null) {
            $returnsQuery->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $returnsQuery->whereDate('created_at', '<=', $endDate);
        }
        if ($isRestricted) {
            $returnsQuery->whereIn('warehouse_id', $allowedWarehouseIds);
        }
        if (!empty($filters['warehouse_id'])) {
            $returnsQuery->where('warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['customer_id'])) {
            $returnsQuery->where('customer_id', (int) $filters['customer_id']);
        }

        $returnedAmount = (float) $returnsQuery->sum('grand_total');

        $totalSales = (float) ($row->total_sales ?? 0.0);
        $paidAmount = (float) ($row->paid_amount ?? 0.0);
        $dueAmount = (float) ($row->due_amount ?? 0.0);
        $netSales = max(0.0, $totalSales - $returnedAmount);

        return [
            'total_sales' => round($totalSales, 2),
            'paid_amount' => round($paidAmount, 2),
            'due_amount' => round($dueAmount, 2),
            'total_orders' => (int) ($row->total_orders ?? 0),
            'total_items' => (int) ($row->total_items ?? 0),
            'returned_amount' => round($returnedAmount, 2),
            'net_sales' => round($netSales, 2),
        ];
    }

    /**
     * Aggregate today's sales summary.
     */
    public function todaySummary(AssistantAccessContext|WarehouseScope $context, array $filters = []): array
    {
        $today = Carbon::today()->toDateString();
        return $this->summary($context, $today, $today, $filters);
    }

    /**
     * Bounded list of recent sales.
     *
     * @return array<array{
     *     reference_no: string,
     *     date: string,
     *     customer: string,
     *     warehouse: string,
     *     grand_total: float,
     *     paid_amount: float,
     *     status: string
     * }>
     */
    public function recentSales(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10,
        array $filters = []
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = $this->baseQuery($context)
            ->leftJoin('customers', 'sales.customer_id', '=', 'customers.id')
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->select([
                'sales.reference_no',
                'sales.created_at',
                'customers.name as customer_name',
                'warehouses.name as warehouse_name',
                'sales.grand_total',
                'sales.paid_amount',
                'sales.payment_status',
            ])
            ->orderBy('sales.created_at', 'desc')
            ->orderBy('sales.id', 'desc')
            ->limit($boundedLimit);

        if (!empty($filters['customer_id'])) {
            $query->where('sales.customer_id', (int) $filters['customer_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('sales.warehouse_id', (int) $filters['warehouse_id']);
        }

        $results = [];
        foreach ($query->get() as $sale) {
            $status = match ((int) $sale->payment_status) {
                1 => 'Pending / Due',
                2 => 'Due',
                3 => 'Partial',
                4 => 'Paid',
                default => 'Unknown',
            };

            $results[] = [
                'reference_no' => (string) $sale->reference_no,
                'date' => substr((string) $sale->created_at, 0, 10),
                'customer' => (string) ($sale->customer_name ?: 'Walk-in'),
                'warehouse' => (string) ($sale->warehouse_name ?: '-'),
                'grand_total' => round((float) $sale->grand_total, 2),
                'paid_amount' => round((float) $sale->paid_amount, 2),
                'status' => $status,
            ];
        }

        return $results;
    }

    /**
     * Top selling products by quantity or revenue.
     */
    public function topSellingProducts(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('product_sales')
            ->join('sales', 'product_sales.sale_id', '=', 'sales.id')
            ->join('products', 'product_sales.product_id', '=', 'products.id');

        $this->applyActiveLifecycle($query, 'sales');
        $this->applyScope($query, $context);

        if ($startDate !== null) {
            $query->whereDate('sales.created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('sales.created_at', '<=', $endDate);
        }

        $rows = $query->groupBy('products.id', 'products.name', 'products.code')
            ->selectRaw('
                products.name,
                products.code,
                SUM(product_sales.qty) as total_qty,
                SUM(product_sales.total) as total_revenue
            ')
            ->orderByDesc('total_qty')
            ->limit($boundedLimit)
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'qty' => (float) $row->total_qty,
                'revenue' => round((float) $row->total_revenue, 2),
            ];
        }

        return $results;
    }

    /**
     * Slow moving products (no sales or very few sales in recent days).
     */
    public function slowMovingProducts(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10,
        int $days = 30
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));
        $sinceDate = now()->subDays($days)->toDateString();

        $recentSoldProductIds = DB::table('product_sales')
            ->join('sales', 'product_sales.sale_id', '=', 'sales.id');

        $this->applyActiveLifecycle($recentSoldProductIds, 'sales');
        $recentSoldProductIds->whereDate('sales.created_at', '>=', $sinceDate);

        $this->applyScope($recentSoldProductIds, $context);

        $soldIds = $recentSoldProductIds->distinct()->pluck('product_sales.product_id')->all();

        $query = DB::table('products')
            ->where('products.is_active', true)
            ->where('products.qty', '>', 0)
            ->whereNotIn('products.id', $soldIds ?: [0])
            ->select('products.name', 'products.code', 'products.qty')
            ->orderBy('products.qty', 'desc')
            ->limit($boundedLimit);

        $results = [];
        foreach ($query->get() as $row) {
            $results[] = [
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'stock' => (float) $row->qty,
            ];
        }

        return $results;
    }

    /**
     * Bounded top products report for a given date and scope.
     *
     * @return array<array{product: string, qty_sold: float, sales_value: float}>
     */
    public function topProductsReport(
        AssistantAccessContext|WarehouseScope $context,
        ?string $date = null,
        int $limit = 10
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));
        $queryDate = $date ?? \Illuminate\Support\Carbon::today()->toDateString();

        $query = DB::table('product_sales as ps')
            ->join('sales', 'ps.sale_id', '=', 'sales.id')
            ->join('products', 'ps.product_id', '=', 'products.id')
            ->select(
                'ps.product_id',
                'products.name as product_name',
                'products.code as product_code',
                DB::raw('SUM(ps.qty) as qty_sold'),
                DB::raw('SUM(ps.total) as sales_value')
            );

        $this->applyActiveLifecycle($query, 'sales');

        $query->whereDate('sales.created_at', $queryDate)
            ->groupBy('ps.product_id', 'products.name', 'products.code')
            ->orderByRaw('SUM(ps.qty) DESC')
            ->orderBy('products.name', 'ASC')
            ->limit($boundedLimit);

        $this->applyScope($query, $context);

        $rows = $query->get();

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'product' => $row->product_name . ($row->product_code ? " ({$row->product_code})" : ''),
                'qty_sold' => (float) $row->qty_sold,
                'sales_value' => (float) $row->sales_value,
            ];
        }

        return $results;
    }

    /**
     * Sales agent performance metrics.
     *
     * @return array<array{
     *     agent_id: int,
     *     agent_name: string,
     *     commission_percent: float,
     *     sales_count: int,
     *     total_revenue: float,
     *     estimated_commission: float
     * }>
     */
    public function agentPerformance(
        AssistantAccessContext|WarehouseScope $context,
        ?int $agentId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        int $limit = 10
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('sales');
        $this->applyActiveLifecycle($query, 'sales');

        $this->applyScope($query, $context);

        if ($startDate !== null) {
            $query->whereDate('sales.created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('sales.created_at', '<=', $endDate);
        }

        // Sales agent association: either direct sales_agent_id or via employee linked to sales.user_id
        $query->leftJoin('employees as e', 'sales.sales_agent_id', '=', 'e.id')
            ->leftJoin('employees as e_fallback', function ($join) {
                $join->on('sales.user_id', '=', 'e_fallback.user_id')
                    ->where('e_fallback.is_sale_agent', 1);
            })
            ->where(function ($q) {
                $q->whereNotNull('sales.sales_agent_id')
                  ->orWhereNotNull('e_fallback.id');
            });

        if ($agentId !== null) {
            $query->where(function ($q) use ($agentId) {
                $q->where('sales.sales_agent_id', $agentId)
                  ->orWhere('e_fallback.id', $agentId);
            });
        }

        $rows = $query->groupBy(
            DB::raw('COALESCE(sales.sales_agent_id, e_fallback.id)'),
            DB::raw('COALESCE(e.name, e_fallback.name)'),
            DB::raw('COALESCE(e.sale_commission_percent, e_fallback.sale_commission_percent, 0)')
        )
        ->select([
            DB::raw('COALESCE(sales.sales_agent_id, e_fallback.id) as agent_id'),
            DB::raw('COALESCE(e.name, e_fallback.name) as agent_name'),
            DB::raw('COALESCE(e.sale_commission_percent, e_fallback.sale_commission_percent, 0) as commission_percent'),
            DB::raw('COUNT(sales.id) as sales_count'),
            DB::raw('COALESCE(SUM(sales.grand_total), 0) as total_revenue'),
        ])
        ->orderByDesc('total_revenue')
        ->limit($boundedLimit)
        ->get();

        $results = [];
        foreach ($rows as $row) {
            $revenue = (float) $row->total_revenue;
            $commRate = (float) $row->commission_percent;
            $commEst = round(($revenue * $commRate) / 100, 2);

            $results[] = [
                'agent_id' => (int) $row->agent_id,
                'agent_name' => (string) $row->agent_name,
                'commission_percent' => $commRate,
                'sales_count' => (int) $row->sales_count,
                'total_revenue' => round($revenue, 2),
                'estimated_commission' => $commEst,
            ];
        }

        return $results;
    }
}
