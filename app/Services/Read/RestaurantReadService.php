<?php

namespace App\Services\Read;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class RestaurantReadService
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('tables') && Schema::hasTable('restaurant_settings');
    }

    private function resolveScope(AssistantAccessContext|WarehouseScope $context): array
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        return [$isRestricted, $allowedWarehouseIds, $ownUserId];
    }

    /**
     * Restaurant dining table summary and occupancy rate.
     *
     * @return array{
     *     available: bool,
     *     reason?: string,
     *     total_tables: int,
     *     occupied_tables: int,
     *     available_tables: int,
     *     occupancy_rate_pct: float
     * }
     */
    public function tableSummary(AssistantAccessContext|WarehouseScope $context): array
    {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'reason' => 'Restaurant module is not active or enabled on this system.',
                'total_tables' => 0,
                'occupied_tables' => 0,
                'available_tables' => 0,
                'occupancy_rate_pct' => 0.0,
            ];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'available' => true,
                'total_tables' => 0,
                'occupied_tables' => 0,
                'available_tables' => 0,
                'occupancy_rate_pct' => 0.0,
            ];
        }

        $totalTables = DB::table('tables')->where('is_active', true)->count();

        // Occupied tables: currently have an active, non-draft, non-voided sale that is not fully paid
        $occupiedQuery = DB::table('sales')
            ->whereNotNull('sales.table_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', 3)
            ->where('sales.payment_status', '!=', 4);

        if ($isRestricted) {
            $occupiedQuery->whereIn('sales.warehouse_id', $allowedWarehouseIds);
        }

        if ($ownUserId !== null) {
            $occupiedQuery->where('sales.user_id', $ownUserId);
        }

        $occupiedCount = $occupiedQuery->distinct('sales.table_id')->count('sales.table_id');
        $availableCount = max(0, $totalTables - $occupiedCount);
        $occupancyRate = $totalTables > 0 ? round(($occupiedCount / $totalTables) * 100, 2) : 0.0;

        return [
            'available' => true,
            'total_tables' => $totalTables,
            'occupied_tables' => $occupiedCount,
            'available_tables' => $availableCount,
            'occupancy_rate_pct' => $occupancyRate,
        ];
    }

    /**
     * Bounded list of dining tables with occupancy status.
     *
     * @return array<array{
     *     table_id: int,
     *     name: string,
     *     capacity: int,
     *     floor_id: int,
     *     status: 'occupied'|'available',
     *     order_reference: string|null,
     *     order_total: float|null
     * }>
     */
    public function tableList(AssistantAccessContext|WarehouseScope $context, int $limit = 20): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $tables = DB::table('tables')
            ->where('is_active', true)
            ->orderBy('floor_id', 'asc')
            ->orderBy('name', 'asc')
            ->limit($boundedLimit)
            ->get();

        // Query active open sales by table
        $salesQuery = DB::table('sales')
            ->whereNotNull('sales.table_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', 3)
            ->where('sales.payment_status', '!=', 4);

        if ($isRestricted) {
            $salesQuery->whereIn('sales.warehouse_id', $allowedWarehouseIds);
        }

        if ($ownUserId !== null) {
            $salesQuery->where('sales.user_id', $ownUserId);
        }

        $activeSales = $salesQuery->select(['sales.table_id', 'sales.reference_no', 'sales.grand_total'])
            ->get()
            ->keyBy('table_id');

        $results = [];
        foreach ($tables as $t) {
            $hasSale = $activeSales->get($t->id);
            $results[] = [
                'table_id' => (int) $t->id,
                'name' => (string) $t->name,
                'capacity' => (int) ($t->number_of_person ?? 0),
                'floor_id' => (int) $t->floor_id,
                'status' => $hasSale ? 'occupied' : 'available',
                'order_reference' => $hasSale ? (string) $hasSale->reference_no : null,
                'order_total' => $hasSale ? round((float) $hasSale->grand_total, 2) : null,
            ];
        }

        return $results;
    }

    /**
     * Open restaurant orders at tables.
     */
    public function openOrders(
        AssistantAccessContext|WarehouseScope $context,
        int $limit = 10
    ): array {
        if (!$this->isAvailable()) {
            return [];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('sales')
            ->join('tables', 'sales.table_id', '=', 'tables.id')
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_status', '!=', 3)
            ->where('sales.payment_status', '!=', 4)
            ->select([
                'sales.id',
                'sales.reference_no',
                'sales.created_at',
                'tables.name as table_name',
                'warehouses.name as warehouse_name',
                'sales.grand_total',
                'sales.paid_amount',
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
        foreach ($query->get() as $row) {
            $grand = (float) $row->grand_total;
            $paid = (float) $row->paid_amount;
            $results[] = [
                'id' => (int) $row->id,
                'reference_no' => (string) $row->reference_no,
                'table_name' => (string) $row->table_name,
                'warehouse_name' => (string) ($row->warehouse_name ?? 'N/A'),
                'grand_total' => round($grand, 2),
                'paid_amount' => round($paid, 2),
                'due_amount' => max(0.0, round($grand - $paid, 2)),
                'item_count' => (int) $row->item_count,
                'time' => substr((string) $row->created_at, 11, 5),
            ];
        }

        return $results;
    }
}
