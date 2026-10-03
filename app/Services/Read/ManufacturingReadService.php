<?php

namespace App\Services\Read;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class ManufacturingReadService
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('productions');
    }

    private function resolveScope(AssistantAccessContext|WarehouseScope $context): array
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        return [$isRestricted, $allowedWarehouseIds, $ownUserId];
    }

    /**
     * Aggregate manufacturing / production summary.
     */
    public function summary(AssistantAccessContext|WarehouseScope $context): array
    {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'reason' => 'Manufacturing module is not active or enabled on this system.',
                'total_productions' => 0,
                'completed_productions' => 0,
                'total_quantity_produced' => 0.0,
                'total_production_cost' => 0.0,
            ];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'available' => true,
                'total_productions' => 0,
                'completed_productions' => 0,
                'total_quantity_produced' => 0.0,
                'total_production_cost' => 0.0,
            ];
        }

        $query = DB::table('productions');

        if ($isRestricted) {
            $query->whereIn('productions.warehouse_id', $allowedWarehouseIds);
        }

        if ($ownUserId !== null) {
            $query->where('productions.user_id', $ownUserId);
        }

        $row = $query->selectRaw('
            COUNT(productions.id) as total_productions,
            COUNT(CASE WHEN productions.status = 1 THEN 1 END) as completed_productions,
            COALESCE(SUM(productions.total_qty), 0) as total_quantity_produced,
            COALESCE(SUM(productions.grand_total), 0) as total_production_cost
        ')->first();

        return [
            'available' => true,
            'total_productions' => (int) ($row->total_productions ?? 0),
            'completed_productions' => (int) ($row->completed_productions ?? 0),
            'total_quantity_produced' => (float) ($row->total_quantity_produced ?? 0.0),
            'total_production_cost' => round((float) ($row->total_production_cost ?? 0.0), 2),
        ];
    }

    /**
     * Bounded list of recent production orders.
     */
    public function recentProductions(
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

        $query = DB::table('productions')
            ->leftJoin('warehouses', 'productions.warehouse_id', '=', 'warehouses.id')
            ->select([
                'productions.id',
                'productions.reference_no',
                'productions.created_at',
                'productions.warehouse_id',
                'warehouses.name as warehouse_name',
                'productions.item as item_count',
                'productions.total_qty',
                'productions.production_cost',
                'productions.grand_total',
                'productions.status',
            ])
            ->orderBy('productions.created_at', 'desc')
            ->limit($boundedLimit);

        if ($isRestricted) {
            $query->whereIn('productions.warehouse_id', $allowedWarehouseIds);
        }

        if ($ownUserId !== null) {
            $query->where('productions.user_id', $ownUserId);
        }

        $results = [];
        foreach ($query->get() as $row) {
            $statusLabel = match ((int) $row->status) {
                1 => 'Completed',
                2 => 'In Progress',
                default => 'Draft',
            };

            $results[] = [
                'id' => (int) $row->id,
                'reference_no' => (string) $row->reference_no,
                'date' => substr((string) $row->created_at, 0, 10),
                'warehouse_name' => (string) ($row->warehouse_name ?? 'N/A'),
                'item_count' => (int) $row->item_count,
                'total_qty' => (float) $row->total_qty,
                'production_cost' => round((float) $row->production_cost, 2),
                'grand_total' => round((float) $row->grand_total, 2),
                'status' => (int) $row->status,
                'status_label' => $statusLabel,
            ];
        }

        return $results;
    }
}
