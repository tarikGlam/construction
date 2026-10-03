<?php

namespace App\Services\Read;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class InventoryReadService
{
    private function resolveScope(AssistantAccessContext|WarehouseScope $context): array
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        return [$isRestricted, $allowedWarehouseIds, $ownUserId];
    }

    /**
     * Stock level for products, respecting warehouse restriction.
     */
    public function stock(
        AssistantAccessContext|WarehouseScope $context,
        ?int $productId = null,
        array $filters = []
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        if ($ownUserId !== null) {
            return [];
        }

        $query = DB::table('products')
            ->where('products.is_active', true);

        if ($productId !== null) {
            $query->where('products.id', $productId);
        }
        if (!empty($filters['code'])) {
            $query->where('products.code', $filters['code']);
        }
        if (!empty($filters['name'])) {
            $query->where('products.name', 'like', '%' . $filters['name'] . '%');
        }

        if ($isRestricted) {
            $query->join('product_warehouse', function ($join) use ($allowedWarehouseIds) {
                $join->on('products.id', '=', 'product_warehouse.product_id')
                    ->whereIn('product_warehouse.warehouse_id', $allowedWarehouseIds);
            })
            ->selectRaw('products.id, products.name, products.code, products.alert_quantity, SUM(product_warehouse.qty) as current_qty')
            ->groupBy('products.id', 'products.name', 'products.code', 'products.alert_quantity');
        } else {
            $query->select('products.id', 'products.name', 'products.code', 'products.alert_quantity', 'products.qty as current_qty');
        }

        $rows = $query->orderBy('products.name')->limit(50)->get();

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'product_id' => (int) $row->id,
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'current_qty' => (float) $row->current_qty,
                'alert_quantity' => (float) ($row->alert_quantity ?? 0),
            ];
        }

        return $results;
    }

    /**
     * Low stock items below or equal to alert quantity.
     */
    public function lowStock(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        if ($ownUserId !== null) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        if ($isRestricted) {
            $query = DB::table('product_warehouse')
                ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                ->join('warehouses', 'product_warehouse.warehouse_id', '=', 'warehouses.id')
                ->where('products.is_active', true)
                ->whereIn('product_warehouse.warehouse_id', $allowedWarehouseIds)
                ->whereNotNull('products.alert_quantity')
                ->whereRaw('product_warehouse.qty <= products.alert_quantity')
                ->where('product_warehouse.qty', '>', 0)
                ->select([
                    'products.id as product_id',
                    'products.name',
                    'products.code',
                    'product_warehouse.qty as current_qty',
                    'products.alert_quantity',
                    'warehouses.name as warehouse_name',
                ])
                ->orderBy('product_warehouse.qty', 'asc')
                ->limit($boundedLimit);
        } else {
            $query = DB::table('products')
                ->where('products.is_active', true)
                ->whereNotNull('products.alert_quantity')
                ->whereRaw('products.qty <= products.alert_quantity')
                ->where('products.qty', '>', 0)
                ->select([
                    'products.id as product_id',
                    'products.name',
                    'products.code',
                    'products.qty as current_qty',
                    'products.alert_quantity',
                ])
                ->orderBy('products.qty', 'asc')
                ->limit($boundedLimit);
        }

        $results = [];
        foreach ($query->get() as $row) {
            $results[] = [
                'product_id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'current_qty' => (float) $row->current_qty,
                'alert_quantity' => (float) $row->alert_quantity,
                'warehouse' => isset($row->warehouse_name) ? (string) $row->warehouse_name : 'All Warehouses',
            ];
        }

        return $results;
    }

    /**
     * Completely out of stock products (qty <= 0).
     */
    public function outOfStock(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        if ($ownUserId !== null) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        if ($isRestricted) {
            $query = DB::table('product_warehouse')
                ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                ->join('warehouses', 'product_warehouse.warehouse_id', '=', 'warehouses.id')
                ->where('products.is_active', true)
                ->whereIn('product_warehouse.warehouse_id', $allowedWarehouseIds)
                ->where('product_warehouse.qty', '<=', 0)
                ->select([
                    'products.id as product_id',
                    'products.name',
                    'products.code',
                    'product_warehouse.qty as current_qty',
                    'warehouses.name as warehouse_name',
                ])
                ->limit($boundedLimit);
        } else {
            $query = DB::table('products')
                ->where('products.is_active', true)
                ->where('products.qty', '<=', 0)
                ->select([
                    'products.id as product_id',
                    'products.name',
                    'products.code',
                    'products.qty as current_qty',
                ])
                ->limit($boundedLimit);
        }

        $results = [];
        foreach ($query->get() as $row) {
            $results[] = [
                'product_id' => (int) $row->product_id,
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'current_qty' => (float) $row->current_qty,
                'warehouse' => isset($row->warehouse_name) ? (string) $row->warehouse_name : 'All Warehouses',
            ];
        }

        return $results;
    }

    /**
     * Product batch stock levels, scoped to warehouse access and optional filters.
     */
    public function batchStock(
        AssistantAccessContext|WarehouseScope $context,
        ?int $productId = null,
        int $limit = 10,
        array $filters = []
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        if ($ownUserId !== null) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        if ($isRestricted) {
            $query = DB::table('product_batches as pb')
                ->join('products as p', 'pb.product_id', '=', 'p.id')
                ->join('product_warehouse as pw', 'pw.product_batch_id', '=', 'pb.id')
                ->join('warehouses as w', 'pw.warehouse_id', '=', 'w.id')
                ->where('pw.qty', '>', 0)
                ->whereIn('pw.warehouse_id', $allowedWarehouseIds)
                ->select([
                    'pb.id as batch_id',
                    'p.id as product_id',
                    'p.name as product_name',
                    'p.code as product_code',
                    'pb.batch_no',
                    'pb.expired_date',
                    'pw.qty as qty',
                    'w.name as warehouse_name',
                ])
                ->orderBy('pb.expired_date', 'asc')
                ->limit($boundedLimit);
        } else {
            $query = DB::table('product_batches as pb')
                ->join('products as p', 'pb.product_id', '=', 'p.id')
                ->where('pb.qty', '>', 0)
                ->select([
                    'pb.id as batch_id',
                    'p.id as product_id',
                    'p.name as product_name',
                    'p.code as product_code',
                    'pb.batch_no',
                    'pb.expired_date',
                    'pb.qty as qty',
                ])
                ->orderBy('pb.expired_date', 'asc')
                ->limit($boundedLimit);

            if (!empty($filters['warehouse_id'])) {
                $query->join('product_warehouse as pw', 'pw.product_batch_id', '=', 'pb.id')
                    ->join('warehouses as w', 'pw.warehouse_id', '=', 'w.id')
                    ->where('pw.warehouse_id', (int) $filters['warehouse_id'])
                    ->where('pw.qty', '>', 0)
                    ->addSelect('w.name as warehouse_name', 'pw.qty as qty');
            }
        }

        if ($productId !== null) {
            $query->where('pb.product_id', $productId);
        }

        if (!empty($filters['batch_no'])) {
            $query->where('pb.batch_no', 'like', '%' . $filters['batch_no'] . '%');
        }

        $results = [];
        foreach ($query->get() as $row) {
            $results[] = [
                'batch_id' => (int) $row->batch_id,
                'product_id' => (int) $row->product_id,
                'product' => (string) $row->product_name,
                'product_code' => (string) $row->product_code,
                'batch_no' => (string) $row->batch_no,
                'expired_date' => (string) ($row->expired_date ?: '-'),
                'qty' => (float) $row->qty,
                'warehouse' => isset($row->warehouse_name) ? (string) $row->warehouse_name : 'All Warehouses',
            ];
        }

        return $results;
    }

    /**
     * Product batch expiry alerts (expired or expiring within threshold).
     *
     * @return array<array{
     *     batch_id: int,
     *     product_id: int,
     *     product: string,
     *     product_code: string,
     *     batch_no: string,
     *     expired_date: string,
     *     days_remaining: int,
     *     status: string,
     *     qty: float,
     *     warehouse: string
     * }>
     */
    public function batchExpiryAlerts(
        AssistantAccessContext|WarehouseScope $context,
        int $daysThreshold = 30,
        int $limit = 15,
        array $filters = []
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        if ($ownUserId !== null) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));
        $today = Carbon::today();
        $cutoffDate = $today->copy()->addDays($daysThreshold)->toDateString();

        if ($isRestricted) {
            $query = DB::table('product_batches as pb')
                ->join('products as p', 'pb.product_id', '=', 'p.id')
                ->join('product_warehouse as pw', 'pw.product_batch_id', '=', 'pb.id')
                ->join('warehouses as w', 'pw.warehouse_id', '=', 'w.id')
                ->where('pw.qty', '>', 0)
                ->whereIn('pw.warehouse_id', $allowedWarehouseIds)
                ->whereNotNull('pb.expired_date')
                ->where('pb.expired_date', '<=', $cutoffDate)
                ->select([
                    'pb.id as batch_id',
                    'p.id as product_id',
                    'p.name as product_name',
                    'p.code as product_code',
                    'pb.batch_no',
                    'pb.expired_date',
                    'pw.qty as qty',
                    'w.name as warehouse_name',
                ])
                ->orderBy('pb.expired_date', 'asc')
                ->limit($boundedLimit);
        } else {
            $query = DB::table('product_batches as pb')
                ->join('products as p', 'pb.product_id', '=', 'p.id')
                ->where('pb.qty', '>', 0)
                ->whereNotNull('pb.expired_date')
                ->where('pb.expired_date', '<=', $cutoffDate)
                ->select([
                    'pb.id as batch_id',
                    'p.id as product_id',
                    'p.name as product_name',
                    'p.code as product_code',
                    'pb.batch_no',
                    'pb.expired_date',
                    'pb.qty as qty',
                ])
                ->orderBy('pb.expired_date', 'asc')
                ->limit($boundedLimit);

            if (!empty($filters['warehouse_id'])) {
                $query->join('product_warehouse as pw', 'pw.product_batch_id', '=', 'pb.id')
                    ->join('warehouses as w', 'pw.warehouse_id', '=', 'w.id')
                    ->where('pw.warehouse_id', (int) $filters['warehouse_id'])
                    ->where('pw.qty', '>', 0)
                    ->addSelect('w.name as warehouse_name', 'pw.qty as qty');
            }
        }

        if (!empty($filters['expired_only'])) {
            $query->where('pb.expired_date', '<', $today->toDateString());
        } elseif (!empty($filters['unexpired_only'])) {
            $query->where('pb.expired_date', '>=', $today->toDateString());
        }

        if (!empty($filters['product_id'])) {
            $query->where('pb.product_id', (int) $filters['product_id']);
        }

        $results = [];
        foreach ($query->get() as $row) {
            $expDate = Carbon::parse($row->expired_date)->startOfDay();
            $diffDays = (int) $today->diffInDays($expDate, false);

            if ($diffDays < 0) {
                $status = 'expired';
            } elseif ($diffDays === 0) {
                $status = 'expiring_today';
            } else {
                $status = 'expiring_soon';
            }

            $results[] = [
                'batch_id' => (int) $row->batch_id,
                'product_id' => (int) $row->product_id,
                'product' => (string) $row->product_name,
                'product_code' => (string) $row->product_code,
                'batch_no' => (string) $row->batch_no,
                'expired_date' => (string) $row->expired_date,
                'days_remaining' => $diffDays,
                'status' => $status,
                'qty' => (float) $row->qty,
                'warehouse' => isset($row->warehouse_name) ? (string) $row->warehouse_name : 'All Warehouses',
            ];
        }

        return $results;
    }

    /**
     * Bounded low-stock report returning total low stock count and table rows.
     *
     * @return array{
     *     total_count: int,
     *     rows: array<array{product: string, warehouse_id: int, current_qty: float, alert_qty: float}>
     * }
     */
    public function lowStockReport(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 15
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return ['total_count' => 0, 'rows' => []];
        }

        if ($ownUserId !== null) {
            return ['total_count' => 0, 'rows' => []];
        }

        $boundedLimit = max(1, min($limit, 50));

        $baseQuery = DB::table('product_warehouse as pw')
            ->join('products', 'pw.product_id', '=', 'products.id')
            ->select(
                'products.id as product_id',
                'products.name as product_name',
                'products.code as product_code',
                'products.alert_quantity',
                'pw.warehouse_id',
                DB::raw('SUM(pw.qty) as current_qty')
            )
            ->where('products.is_active', true)
            ->whereNotNull('products.alert_quantity')
            ->groupBy('pw.product_id', 'pw.warehouse_id', 'products.id', 'products.name', 'products.code', 'products.alert_quantity')
            ->havingRaw('SUM(pw.qty) <= products.alert_quantity');

        if ($isRestricted) {
            $baseQuery->whereIn('pw.warehouse_id', $allowedWarehouseIds);
        }

        $totalCount = (int) DB::query()->fromSub($baseQuery, 'sub')->count();

        $rows = (clone $baseQuery)
            ->orderByRaw('SUM(pw.qty) ASC')
            ->orderBy('products.name', 'ASC')
            ->limit($boundedLimit)
            ->get();

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                'product' => $row->product_name . ($row->product_code ? " ({$row->product_code})" : ''),
                'warehouse_id' => (int) $row->warehouse_id,
                'current_qty' => (float) $row->current_qty,
                'alert_qty' => (float) $row->alert_quantity,
            ];
        }

        return [
            'total_count' => $totalCount,
            'rows' => $tableRows,
        ];
    }

    /**
     * Authoritative slow-moving products report (products with stock but no net sales in lookback period).
     *
     * @return array{
     *     total_count: int,
     *     rows: array<array{name_code: string, stock: float, sales: float, last_sale: string}>
     * }
     */
    public function slowMovingProductsReport(
        AssistantAccessContext|WarehouseScope $context,
        int $lookbackDays = 30,
        int $limit = 10
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return ['total_count' => 0, 'rows' => []];
        }

        if ($ownUserId !== null) {
            return ['total_count' => 0, 'rows' => []];
        }

        $boundedLimit = max(1, min($limit, 50));
        $lookbackDate = now()->subDays($lookbackDays)->startOfDay();

        $recentSalesQuery = DB::table('product_sales')
            ->join('sales', 'product_sales.sale_id', '=', 'sales.id')
            ->selectRaw('COALESCE(SUM(product_sales.qty), 0)')
            ->whereColumn('product_sales.product_id', 'products.id')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', 3)
            ->whereNull('sales.voided_at')
            ->where(function ($q) {
                $q->whereNull('sales.accounting_status')
                    ->orWhereNotIn('sales.accounting_status', ['reversed', 'voided']);
            })
            ->where(function($q) {
                $q->whereNull('sales.sale_type')
                  ->orWhereRaw('LOWER(sales.sale_type) <> ?', ['opening balance']);
            })
            ->where('sales.created_at', '>=', $lookbackDate);

        if ($isRestricted) {
            $recentSalesQuery->whereIn('sales.warehouse_id', $allowedWarehouseIds);
        }

        $recentReturnsQuery = DB::table('product_returns')
            ->join('returns', 'product_returns.return_id', '=', 'returns.id')
            ->selectRaw('COALESCE(SUM(product_returns.qty), 0)')
            ->whereColumn('product_returns.product_id', 'products.id')
            ->where(function ($q) {
                $q->whereNull('returns.accounting_status')
                    ->orWhereNotIn('returns.accounting_status', ['reversed', 'voided']);
            })
            ->where('returns.created_at', '>=', $lookbackDate);

        if ($isRestricted) {
            $recentReturnsQuery->whereIn('returns.warehouse_id', $allowedWarehouseIds);
        }

        $lastSaleQuery = DB::table('product_sales')
            ->join('sales', 'product_sales.sale_id', '=', 'sales.id')
            ->selectRaw('MAX(sales.created_at)')
            ->whereColumn('product_sales.product_id', 'products.id')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', 3)
            ->whereNull('sales.voided_at')
            ->where(function ($q) {
                $q->whereNull('sales.accounting_status')
                    ->orWhereNotIn('sales.accounting_status', ['reversed', 'voided']);
            })
            ->where(function($q) {
                $q->whereNull('sales.sale_type')
                  ->orWhereRaw('LOWER(sales.sale_type) <> ?', ['opening balance']);
            });

        if ($isRestricted) {
            $lastSaleQuery->whereIn('sales.warehouse_id', $allowedWarehouseIds);
        }

        $currentStockQuery = DB::table('product_warehouse')
            ->selectRaw('COALESCE(SUM(qty), 0)')
            ->whereColumn('product_warehouse.product_id', 'products.id');

        if ($isRestricted) {
            $currentStockQuery->whereIn('product_warehouse.warehouse_id', $allowedWarehouseIds);
        }

        $query = DB::table('products')
            ->select('name', 'code')
            ->selectSub($currentStockQuery, 'current_stock')
            ->selectSub($recentSalesQuery, 'recent_sales')
            ->selectSub($recentReturnsQuery, 'recent_returns')
            ->selectSub($lastSaleQuery, 'last_sale_date')
            ->where('is_active', true)
            ->having('current_stock', '>', 0)
            ->havingRaw('(recent_sales - recent_returns) <= 0');

        $totalSlowProducts = DB::query()->fromSub($query, 'sub')->count();

        $rows = $query->orderByRaw('last_sale_date IS NOT NULL') // nulls first
            ->orderBy('last_sale_date', 'asc')
            ->orderByRaw('(recent_sales - recent_returns) asc')
            ->orderBy('name', 'asc')
            ->orderBy('code', 'asc')
            ->limit($boundedLimit)
            ->get();

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                'name_code' => $row->name . ' (' . $row->code . ')',
                'stock' => (float) $row->current_stock,
                'sales' => (float) ($row->recent_sales - $row->recent_returns),
                'last_sale' => $row->last_sale_date ? \Carbon\Carbon::parse($row->last_sale_date)->format('Y-m-d') : __('db.ai_assistant_no_sale'),
            ];
        }

        return [
            'total_count' => $totalSlowProducts,
            'rows' => $tableRows,
        ];
    }
}
