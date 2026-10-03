<?php

namespace App\Services\Read;

use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class QuotationReadService
{
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
     * Apply warehouse and own-user authorization boundaries to any quotation query.
     */
    public function applyScope(
        $query,
        AssistantAccessContext|WarehouseScope $context,
        string $warehouseColumn = 'quotations.warehouse_id',
        string $userColumn = 'quotations.user_id'
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
     * Aggregate quotation summary.
     *
     * @return array{
     *     total_quotations: int,
     *     total_amount: float,
     *     pending_quotations: int,
     *     sent_quotations: int
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
                'total_quotations' => 0,
                'total_amount' => 0.0,
                'pending_quotations' => 0,
                'sent_quotations' => 0,
            ];
        }

        $query = DB::table('quotations');
        $this->applyScope($query, $context);

        if ($startDate !== null) {
            $query->whereDate('quotations.created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->whereDate('quotations.created_at', '<=', $endDate);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('quotations.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('quotations.customer_id', (int) $filters['customer_id']);
        }

        $row = $query->selectRaw('
            COUNT(quotations.id) as total_quotations,
            COALESCE(SUM(quotations.grand_total), 0) as total_amount,
            COUNT(CASE WHEN quotations.quotation_status = 1 THEN 1 END) as pending_quotations,
            COUNT(CASE WHEN quotations.quotation_status = 2 THEN 1 END) as sent_quotations
        ')->first();

        return [
            'total_quotations' => (int) ($row->total_quotations ?? 0),
            'total_amount' => round((float) ($row->total_amount ?? 0.0), 2),
            'pending_quotations' => (int) ($row->pending_quotations ?? 0),
            'sent_quotations' => (int) ($row->sent_quotations ?? 0),
        ];
    }

    /**
     * Bounded list of quotations.
     *
     * @return array<array{
     *     id: int,
     *     reference_no: string,
     *     date: string,
     *     customer_id: int,
     *     customer_name: string,
     *     warehouse_id: int,
     *     warehouse_name: string,
     *     supplier_name: string,
     *     status: int,
     *     status_label: string,
     *     grand_total: float,
     *     item_count: int
     * }>
     */
    public function quotations(
        AssistantAccessContext|WarehouseScope $context,
        ?int $status = null,
        int $limit = 10,
        array $filters = []
    ): array {
        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('quotations');
        $this->applyScope($query, $context);

        if ($status !== null) {
            $query->where('quotations.quotation_status', $status);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('quotations.customer_id', (int) $filters['customer_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('quotations.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['start_date'])) {
            $query->whereDate('quotations.created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->whereDate('quotations.created_at', '<=', $filters['end_date']);
        }

        $query->leftJoin('customers', 'quotations.customer_id', '=', 'customers.id')
            ->leftJoin('warehouses', 'quotations.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('suppliers', 'quotations.supplier_id', '=', 'suppliers.id')
            ->select([
                'quotations.id',
                'quotations.reference_no',
                'quotations.created_at',
                'quotations.customer_id',
                'customers.name as customer_name',
                'quotations.warehouse_id',
                'warehouses.name as warehouse_name',
                'suppliers.name as supplier_name',
                'quotations.quotation_status',
                'quotations.grand_total',
                'quotations.item as item_count',
            ])
            ->orderBy('quotations.created_at', 'desc')
            ->orderBy('quotations.id', 'desc')
            ->limit($boundedLimit);

        $results = [];
        foreach ($query->get() as $row) {
            $statusLabel = match ((int) $row->quotation_status) {
                1 => 'Pending',
                2 => 'Sent',
                default => 'Unknown',
            };

            $results[] = [
                'id' => (int) $row->id,
                'reference_no' => (string) $row->reference_no,
                'date' => substr((string) $row->created_at, 0, 10),
                'customer_id' => (int) $row->customer_id,
                'customer_name' => (string) ($row->customer_name ?? 'N/A'),
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => (string) ($row->warehouse_name ?? 'N/A'),
                'supplier_name' => (string) ($row->supplier_name ?? 'N/A'),
                'status' => (int) $row->quotation_status,
                'status_label' => $statusLabel,
                'grand_total' => round((float) $row->grand_total, 2),
                'item_count' => (int) $row->item_count,
            ];
        }

        return $results;
    }

    /**
     * Line item details for a quotation.
     */
    public function quotationDetails(
        AssistantAccessContext|WarehouseScope $context,
        int|string $idOrReference
    ): ?array {
        $query = DB::table('quotations');

        if (is_numeric($idOrReference)) {
            $query->where('quotations.id', (int) $idOrReference);
        } else {
            $query->where('quotations.reference_no', (string) $idOrReference);
        }

        $this->applyScope($query, $context);

        $quote = $query->leftJoin('customers', 'quotations.customer_id', '=', 'customers.id')
            ->leftJoin('warehouses', 'quotations.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('suppliers', 'quotations.supplier_id', '=', 'suppliers.id')
            ->select([
                'quotations.id',
                'quotations.reference_no',
                'quotations.created_at',
                'quotations.customer_id',
                'customers.name as customer_name',
                'customers.email as customer_email',
                'customers.phone_number as customer_phone',
                'quotations.warehouse_id',
                'warehouses.name as warehouse_name',
                'suppliers.name as supplier_name',
                'quotations.quotation_status',
                'quotations.grand_total',
                'quotations.total_qty',
                'quotations.item as item_count',
                'quotations.note',
            ])
            ->first();

        if (!$quote) {
            return null;
        }

        $items = DB::table('product_quotation as pq')
            ->join('products as p', 'pq.product_id', '=', 'p.id')
            ->where('pq.quotation_id', $quote->id)
            ->select([
                'p.id as product_id',
                'p.name as product_name',
                'p.code as product_code',
                'pq.qty',
                'pq.net_unit_price',
                'pq.discount',
                'pq.tax',
                'pq.total',
            ])
            ->get();

        $lineItems = [];
        foreach ($items as $item) {
            $lineItems[] = [
                'product_id' => (int) $item->product_id,
                'product_name' => (string) $item->product_name,
                'product_code' => (string) $item->product_code,
                'qty' => (float) $item->qty,
                'net_unit_price' => round((float) $item->net_unit_price, 2),
                'discount' => round((float) $item->discount, 2),
                'tax' => round((float) $item->tax, 2),
                'total' => round((float) $item->total, 2),
            ];
        }

        $statusLabel = match ((int) $quote->quotation_status) {
            1 => 'Pending',
            2 => 'Sent',
            default => 'Unknown',
        };

        return [
            'id' => (int) $quote->id,
            'reference_no' => (string) $quote->reference_no,
            'date' => (string) $quote->created_at,
            'customer_name' => (string) ($quote->customer_name ?? 'N/A'),
            'customer_email' => (string) ($quote->customer_email ?? ''),
            'customer_phone' => (string) ($quote->customer_phone ?? ''),
            'warehouse_name' => (string) ($quote->warehouse_name ?? 'N/A'),
            'supplier_name' => (string) ($quote->supplier_name ?? 'N/A'),
            'status' => (int) $quote->quotation_status,
            'status_label' => $statusLabel,
            'grand_total' => round((float) $quote->grand_total, 2),
            'total_qty' => (float) $quote->total_qty,
            'item_count' => (int) $quote->item_count,
            'note' => (string) ($quote->note ?? ''),
            'items' => $lineItems,
        ];
    }
}
