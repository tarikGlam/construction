<?php

namespace App\Services\Read;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class PurchaseReadService
{
    /**
     * Apply warehouse and own-user authorization boundaries to any purchase query.
     */
    public function applyScope(
        $query,
        AssistantAccessContext|WarehouseScope $context,
        string $warehouseColumn = 'purchases.warehouse_id',
        string $userColumn = 'purchases.user_id'
    ) {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

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

    public function baseQuery(AssistantAccessContext|WarehouseScope $context): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('purchases')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 3);

        return $this->applyScope($query, $context);
    }

    /**
     * Aggregate purchase summary for a given period and context.
     *
     * @return array{
     *     total_purchases: float,
     *     paid_amount: float,
     *     due_amount: float,
     *     total_orders: int,
     *     total_items: float
     * }
     */
    public function summary(
        AssistantAccessContext|WarehouseScope $context,
        ?string $startDate = null,
        ?string $endDate = null,
        array $filters = []
    ): array {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'total_purchases' => 0.0,
                'paid_amount' => 0.0,
                'due_amount' => 0.0,
                'total_orders' => 0,
                'total_items' => 0.0,
            ];
        }

        $query = $this->baseQuery($context);

        if ($startDate !== null) {
            $query->whereDate('purchases.created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('purchases.created_at', '<=', $endDate);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('purchases.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('purchases.supplier_id', (int) $filters['supplier_id']);
        }

        $row = $query->selectRaw('
            COALESCE(SUM(purchases.grand_total), 0) as total_purchases,
            COALESCE(SUM(purchases.paid_amount), 0) as paid_amount,
            COUNT(purchases.id) as total_orders,
            COALESCE(SUM(purchases.total_qty), 0) as total_items,
            COALESCE(SUM(CASE WHEN purchases.grand_total > purchases.paid_amount THEN purchases.grand_total - purchases.paid_amount ELSE 0 END), 0) as due_amount
        ')->first();

        $totalPurchases = (float) ($row->total_purchases ?? 0.0);
        $paidAmount = (float) ($row->paid_amount ?? 0.0);
        $dueAmount = (float) ($row->due_amount ?? 0.0);

        return [
            'total_purchases' => round($totalPurchases, 2),
            'paid_amount' => round($paidAmount, 2),
            'due_amount' => round($dueAmount, 2),
            'total_orders' => (int) ($row->total_orders ?? 0),
            'total_items' => (float) ($row->total_items ?? 0.0),
        ];
    }

    /**
     * Aggregate today's purchase summary.
     */
    public function todaySummary(AssistantAccessContext|WarehouseScope $context, array $filters = []): array
    {
        $today = Carbon::today()->toDateString();
        return $this->summary($context, $today, $today, $filters);
    }

    /**
     * Bounded list of recent purchases.
     */
    public function recentPurchases(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10,
        array $filters = []
    ): array {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = $this->baseQuery($context)
            ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoin('warehouses', 'purchases.warehouse_id', '=', 'warehouses.id')
            ->select([
                'purchases.id',
                'purchases.reference_no',
                'purchases.created_at',
                'suppliers.name as supplier_name',
                'warehouses.name as warehouse_name',
                'purchases.grand_total',
                'purchases.paid_amount',
                'purchases.status',
            ])
            ->orderBy('purchases.created_at', 'desc')
            ->orderBy('purchases.id', 'desc')
            ->limit($boundedLimit);

        $results = [];
        foreach ($query->get() as $row) {
            $results[] = [
                'id' => (int) $row->id,
                'reference_no' => (string) $row->reference_no,
                'date' => (string) $row->created_at,
                'supplier' => (string) ($row->supplier_name ?? 'N/A'),
                'warehouse' => (string) ($row->warehouse_name ?? 'N/A'),
                'total' => (float) $row->grand_total,
                'paid' => (float) $row->paid_amount,
                'status' => (int) $row->status,
            ];
        }

        return $results;
    }

    /**
     * Bounded list of purchase orders with optional status filter.
     *
     * @param AssistantAccessContext|WarehouseScope $context
     * @param int|null $status Purchase status: 1=Received, 2=Partial, 3=Pending, 4=Ordered
     * @param int $limit
     * @param array $filters [supplier_id, warehouse_id, payment_status, open_only, start_date, end_date]
     * @return array<array{
     *     id: int,
     *     reference_no: string,
     *     date: string,
     *     supplier_id: int|null,
     *     supplier_name: string,
     *     warehouse_id: int,
     *     warehouse_name: string,
     *     status: int,
     *     status_label: string,
     *     payment_status: int,
     *     payment_status_label: string,
     *     grand_total: float,
     *     paid_amount: float,
     *     due_amount: float,
     *     total_qty: float,
     *     item_count: int
     * }>
     */
    public function purchaseOrders(
        AssistantAccessContext|WarehouseScope $context,
        ?int $status = null,
        int $limit = 10,
        array $filters = []
    ): array {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('purchases')
            ->whereNull('purchases.deleted_at');

        $this->applyScope($query, $context);

        if ($status !== null) {
            $query->where('purchases.status', $status);
        } elseif (!empty($filters['open_only'])) {
            // Open purchase orders: 2=Partial, 3=Pending, 4=Ordered
            $query->whereIn('purchases.status', [2, 3, 4]);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('purchases.supplier_id', (int) $filters['supplier_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('purchases.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['payment_status'])) {
            $query->where('purchases.payment_status', (int) $filters['payment_status']);
        }
        if (!empty($filters['start_date'])) {
            $query->whereDate('purchases.created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->whereDate('purchases.created_at', '<=', $filters['end_date']);
        }

        $query->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoin('warehouses', 'purchases.warehouse_id', '=', 'warehouses.id')
            ->select([
                'purchases.id',
                'purchases.reference_no',
                'purchases.created_at',
                'purchases.supplier_id',
                'suppliers.name as supplier_name',
                'purchases.warehouse_id',
                'warehouses.name as warehouse_name',
                'purchases.status',
                'purchases.payment_status',
                'purchases.grand_total',
                'purchases.paid_amount',
                'purchases.total_qty',
                'purchases.item as item_count',
            ])
            ->orderBy('purchases.created_at', 'desc')
            ->orderBy('purchases.id', 'desc')
            ->limit($boundedLimit);

        $results = [];
        foreach ($query->get() as $row) {
            $grandTotal = (float) $row->grand_total;
            $paidAmount = (float) $row->paid_amount;
            $dueAmount = max(0.0, round($grandTotal - $paidAmount, 2));

            $statusLabel = match ((int) $row->status) {
                1 => 'Received',
                2 => 'Partial',
                3 => 'Pending',
                4 => 'Ordered',
                default => 'Unknown',
            };

            $paymentStatusLabel = match ((int) $row->payment_status) {
                1 => 'Due',
                2 => 'Partial',
                3 => 'Paid',
                default => 'Unknown',
            };

            $results[] = [
                'id' => (int) $row->id,
                'reference_no' => (string) $row->reference_no,
                'date' => (string) $row->created_at,
                'supplier_id' => $row->supplier_id ? (int) $row->supplier_id : null,
                'supplier_name' => (string) ($row->supplier_name ?? 'N/A'),
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => (string) ($row->warehouse_name ?? 'N/A'),
                'status' => (int) $row->status,
                'status_label' => $statusLabel,
                'payment_status' => (int) $row->payment_status,
                'payment_status_label' => $paymentStatusLabel,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'total_qty' => (float) $row->total_qty,
                'item_count' => (int) $row->item_count,
            ];
        }

        return $results;
    }

    /**
     * Orders with pending shipments/arrivals (status in [2, 3, 4]).
     *
     * @return array<array{
     *     purchase_id: int,
     *     reference_no: string,
     *     date: string,
     *     supplier_name: string,
     *     warehouse_name: string,
     *     status: int,
     *     status_label: string,
     *     total_qty: float,
     *     received_qty: float,
     *     pending_qty: float
     * }>
     */
    public function pendingArrivals(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10,
        array $filters = []
    ): array {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('purchases')
            ->whereNull('purchases.deleted_at')
            ->whereIn('purchases.status', [2, 3, 4]);

        $this->applyScope($query, $context);

        if (!empty($filters['supplier_id'])) {
            $query->where('purchases.supplier_id', (int) $filters['supplier_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('purchases.warehouse_id', (int) $filters['warehouse_id']);
        }

        $query->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoin('warehouses', 'purchases.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')
            ->select([
                'purchases.id',
                'purchases.reference_no',
                'purchases.created_at',
                'suppliers.name as supplier_name',
                'warehouses.name as warehouse_name',
                'purchases.status',
                'purchases.total_qty',
                DB::raw('COALESCE(SUM(product_purchases.recieved), 0) as total_received'),
            ])
            ->groupBy(
                'purchases.id',
                'purchases.reference_no',
                'purchases.created_at',
                'suppliers.name',
                'warehouses.name',
                'purchases.status',
                'purchases.total_qty'
            )
            ->orderBy('purchases.created_at', 'asc')
            ->limit($boundedLimit);

        $results = [];
        foreach ($query->get() as $row) {
            $totalQty = (float) $row->total_qty;
            $receivedQty = (float) $row->total_received;
            $pendingQty = max(0.0, round($totalQty - $receivedQty, 2));

            $statusLabel = match ((int) $row->status) {
                2 => 'Partial',
                3 => 'Pending',
                4 => 'Ordered',
                default => 'Unknown',
            };

            $results[] = [
                'purchase_id' => (int) $row->id,
                'reference_no' => (string) $row->reference_no,
                'date' => (string) $row->created_at,
                'supplier_name' => (string) ($row->supplier_name ?? 'N/A'),
                'warehouse_name' => (string) ($row->warehouse_name ?? 'N/A'),
                'status' => (int) $row->status,
                'status_label' => $statusLabel,
                'total_qty' => $totalQty,
                'received_qty' => $receivedQty,
                'pending_qty' => $pendingQty,
            ];
        }

        return $results;
    }

    /**
     * Line item details for a specific purchase order.
     */
    public function purchaseOrderDetails(
        AssistantAccessContext|WarehouseScope $context,
        int|string $idOrReference
    ): ?array {
        $query = DB::table('purchases')
            ->whereNull('purchases.deleted_at');

        if (is_numeric($idOrReference)) {
            $query->where('purchases.id', (int) $idOrReference);
        } else {
            $query->where('purchases.reference_no', (string) $idOrReference);
        }

        $this->applyScope($query, $context);

        $purchase = $query->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->leftJoin('warehouses', 'purchases.warehouse_id', '=', 'warehouses.id')
            ->select([
                'purchases.id',
                'purchases.reference_no',
                'purchases.created_at',
                'purchases.supplier_id',
                'suppliers.name as supplier_name',
                'suppliers.email as supplier_email',
                'suppliers.phone_number as supplier_phone',
                'purchases.warehouse_id',
                'warehouses.name as warehouse_name',
                'purchases.status',
                'purchases.payment_status',
                'purchases.grand_total',
                'purchases.paid_amount',
                'purchases.total_qty',
                'purchases.item as item_count',
                'purchases.note',
            ])
            ->first();

        if (!$purchase) {
            return null;
        }

        $items = DB::table('product_purchases as pp')
            ->join('products as p', 'pp.product_id', '=', 'p.id')
            ->leftJoin('product_batches as pb', 'pp.product_batch_id', '=', 'pb.id')
            ->where('pp.purchase_id', $purchase->id)
            ->select([
                'p.id as product_id',
                'p.name as product_name',
                'p.code as product_code',
                'pb.batch_no',
                'pp.qty',
                'pp.recieved',
                'pp.return_qty',
                'pp.net_unit_cost',
                'pp.total',
            ])
            ->get();

        $lineItems = [];
        foreach ($items as $item) {
            $qty = (float) $item->qty;
            $rec = (float) $item->recieved;
            $lineItems[] = [
                'product_id' => (int) $item->product_id,
                'product_name' => (string) $item->product_name,
                'product_code' => (string) $item->product_code,
                'batch_no' => $item->batch_no ? (string) $item->batch_no : null,
                'qty' => $qty,
                'recieved' => $rec,
                'return_qty' => (float) ($item->return_qty ?? 0),
                'pending_qty' => max(0.0, round($qty - $rec, 2)),
                'net_unit_cost' => (float) $item->net_unit_cost,
                'total' => (float) $item->total,
            ];
        }

        $statusLabel = match ((int) $purchase->status) {
            1 => 'Received',
            2 => 'Partial',
            3 => 'Pending',
            4 => 'Ordered',
            default => 'Unknown',
        };

        return [
            'id' => (int) $purchase->id,
            'reference_no' => (string) $purchase->reference_no,
            'date' => (string) $purchase->created_at,
            'supplier_name' => (string) ($purchase->supplier_name ?? 'N/A'),
            'supplier_email' => (string) ($purchase->supplier_email ?? ''),
            'supplier_phone' => (string) ($purchase->supplier_phone ?? ''),
            'warehouse_name' => (string) ($purchase->warehouse_name ?? 'N/A'),
            'status' => (int) $purchase->status,
            'status_label' => $statusLabel,
            'payment_status' => (int) $purchase->payment_status,
            'grand_total' => (float) $purchase->grand_total,
            'paid_amount' => (float) $purchase->paid_amount,
            'due_amount' => max(0.0, round((float) $purchase->grand_total - (float) $purchase->paid_amount, 2)),
            'total_qty' => (float) $purchase->total_qty,
            'item_count' => (int) $purchase->item_count,
            'note' => (string) ($purchase->note ?? ''),
            'items' => $lineItems,
        ];
    }
}
