<?php

namespace App\Services\Read;

use App\Models\ImportBatch;
use App\Services\ImportBatchProfitabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class LandedCostReadService
{
    public function __construct(
        protected ?ImportBatchProfitabilityService $profitabilityService = null
    ) {
        $this->profitabilityService ??= app(ImportBatchProfitabilityService::class);
    }

    /**
     * Check whether Landed Cost / Import Batch capability is available and operational.
     */
    public function isAvailable(): bool
    {
        return Schema::hasTable('import_batches') && Schema::hasTable('import_batch_costs');
    }

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
     * Apply warehouse and own-user authorization boundaries to import batches query.
     */
    public function applyScope(
        $query,
        AssistantAccessContext|WarehouseScope $context,
        string $warehouseColumn = 'import_batches.warehouse_id',
        string $userColumn = 'import_batches.created_by'
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
     * Aggregate summary of import batches and allocated landed costs.
     */
    public function summary(AssistantAccessContext|WarehouseScope $context): array
    {
        if (!$this->isAvailable()) {
            return [
                'available' => false,
                'reason' => 'Import batch and landed cost module is not active or installed on this system.',
                'total_batches' => 0,
                'finalized_batches' => 0,
                'total_goods_cost' => 0.0,
                'total_landed_cost' => 0.0,
                'total_combined_cost' => 0.0,
            ];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'available' => true,
                'total_batches' => 0,
                'finalized_batches' => 0,
                'total_goods_cost' => 0.0,
                'total_landed_cost' => 0.0,
                'total_combined_cost' => 0.0,
            ];
        }

        $query = DB::table('import_batches')
            ->whereNull('import_batches.deleted_at');

        $this->applyScope($query, $context);

        $row = $query->selectRaw('
            COUNT(import_batches.id) as total_batches,
            COUNT(CASE WHEN import_batches.status = "finalized" THEN 1 END) as finalized_batches,
            COALESCE(SUM(import_batches.total_goods_cost), 0) as total_goods_cost,
            COALESCE(SUM(import_batches.total_landed_cost), 0) as total_landed_cost,
            COALESCE(SUM(import_batches.total_cost), 0) as total_combined_cost
        ')->first();

        return [
            'available' => true,
            'total_batches' => (int) ($row->total_batches ?? 0),
            'finalized_batches' => (int) ($row->finalized_batches ?? 0),
            'total_goods_cost' => round((float) ($row->total_goods_cost ?? 0.0), 2),
            'total_landed_cost' => round((float) ($row->total_landed_cost ?? 0.0), 2),
            'total_combined_cost' => round((float) ($row->total_combined_cost ?? 0.0), 2),
        ];
    }

    /**
     * Bounded list of import batches.
     *
     * @return array<array{
     *     id: int,
     *     batch_number: string,
     *     reference_no: string,
     *     title: string,
     *     warehouse_id: int,
     *     warehouse_name: string,
     *     status: string,
     *     is_locked: bool,
     *     allocation_method: string,
     *     total_goods_cost: float,
     *     total_landed_cost: float,
     *     total_cost: float,
     *     received_at: string|null,
     *     finalized_at: string|null
     * }>
     */
    public function batches(
        AssistantAccessContext|WarehouseScope $context,
        ?string $status = null,
        int $limit = 10,
        array $filters = []
    ): array {
        if (!$this->isAvailable()) {
            return [];
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [];
        }

        $boundedLimit = max(1, min($limit, 50));

        $query = DB::table('import_batches')
            ->whereNull('import_batches.deleted_at');

        $this->applyScope($query, $context);

        if ($status !== null) {
            $query->where('import_batches.status', $status);
        }

        if (!empty($filters['warehouse_id'])) {
            $query->where('import_batches.warehouse_id', (int) $filters['warehouse_id']);
        }

        $query->leftJoin('warehouses', 'import_batches.warehouse_id', '=', 'warehouses.id')
            ->select([
                'import_batches.id',
                'import_batches.batch_number',
                'import_batches.reference_no',
                'import_batches.title',
                'import_batches.warehouse_id',
                'warehouses.name as warehouse_name',
                'import_batches.status',
                'import_batches.is_locked',
                'import_batches.allocation_method',
                'import_batches.total_goods_cost',
                'import_batches.total_landed_cost',
                'import_batches.total_cost',
                'import_batches.received_at',
                'import_batches.finalized_at',
            ])
            ->orderBy('import_batches.created_at', 'desc')
            ->orderBy('import_batches.id', 'desc')
            ->limit($boundedLimit);

        $results = [];
        foreach ($query->get() as $row) {
            $results[] = [
                'id' => (int) $row->id,
                'batch_number' => (string) $row->batch_number,
                'reference_no' => (string) ($row->reference_no ?? ''),
                'title' => (string) $row->title,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => (string) ($row->warehouse_name ?? 'N/A'),
                'status' => (string) $row->status,
                'is_locked' => (bool) $row->is_locked,
                'allocation_method' => (string) $row->allocation_method,
                'total_goods_cost' => (float) $row->total_goods_cost,
                'total_landed_cost' => (float) $row->total_landed_cost,
                'total_cost' => (float) $row->total_cost,
                'received_at' => $row->received_at ? (string) $row->received_at : null,
                'finalized_at' => $row->finalized_at ? (string) $row->finalized_at : null,
            ];
        }

        return $results;
    }

    /**
     * Batch header with associated purchases and landed cost line items.
     */
    public function batchDetails(
        AssistantAccessContext|WarehouseScope $context,
        int|string $idOrNumber
    ): ?array {
        if (!$this->isAvailable()) {
            return null;
        }

        $query = DB::table('import_batches')
            ->whereNull('import_batches.deleted_at');

        if (is_numeric($idOrNumber)) {
            $query->where('import_batches.id', (int) $idOrNumber);
        } else {
            $query->where('import_batches.batch_number', (string) $idOrNumber);
        }

        $this->applyScope($query, $context);

        $batch = $query->leftJoin('warehouses', 'import_batches.warehouse_id', '=', 'warehouses.id')
            ->select([
                'import_batches.id',
                'import_batches.batch_number',
                'import_batches.reference_no',
                'import_batches.title',
                'import_batches.warehouse_id',
                'warehouses.name as warehouse_name',
                'import_batches.status',
                'import_batches.is_locked',
                'import_batches.allocation_method',
                'import_batches.total_goods_cost',
                'import_batches.total_landed_cost',
                'import_batches.total_cost',
                'import_batches.notes',
                'import_batches.received_at',
                'import_batches.finalized_at',
            ])
            ->first();

        if (!$batch) {
            return null;
        }

        // Linked purchases
        $purchases = DB::table('purchases')
            ->leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->where('purchases.import_batch_id', $batch->id)
            ->whereNull('purchases.deleted_at')
            ->select([
                'purchases.id',
                'purchases.reference_no',
                'purchases.grand_total',
                'purchases.total_qty',
                'suppliers.name as supplier_name',
            ])
            ->get();

        $linkedPurchases = [];
        foreach ($purchases as $p) {
            $linkedPurchases[] = [
                'id' => (int) $p->id,
                'reference_no' => (string) $p->reference_no,
                'grand_total' => (float) $p->grand_total,
                'total_qty' => (float) $p->total_qty,
                'supplier_name' => (string) ($p->supplier_name ?? 'N/A'),
            ];
        }

        // Landed cost lines
        $costs = DB::table('import_batch_costs')
            ->where('import_batch_id', $batch->id)
            ->select([
                'id',
                'cost_type',
                'original_amount',
                'exchange_rate',
                'base_amount',
                'reference_no',
                'notes',
            ])
            ->get();

        $costLines = [];
        foreach ($costs as $c) {
            $costLines[] = [
                'id' => (int) $c->id,
                'cost_type' => (string) $c->cost_type,
                'original_amount' => (float) $c->original_amount,
                'exchange_rate' => (float) $c->exchange_rate,
                'base_amount' => (float) $c->base_amount,
                'reference_no' => (string) ($c->reference_no ?? ''),
                'notes' => (string) ($c->notes ?? ''),
            ];
        }

        return [
            'id' => (int) $batch->id,
            'batch_number' => (string) $batch->batch_number,
            'reference_no' => (string) ($batch->reference_no ?? ''),
            'title' => (string) $batch->title,
            'warehouse_name' => (string) ($batch->warehouse_name ?? 'N/A'),
            'status' => (string) $batch->status,
            'is_locked' => (bool) $batch->is_locked,
            'allocation_method' => (string) $batch->allocation_method,
            'total_goods_cost' => (float) $batch->total_goods_cost,
            'total_landed_cost' => (float) $batch->total_landed_cost,
            'total_cost' => (float) $batch->total_cost,
            'notes' => (string) ($batch->notes ?? ''),
            'received_at' => $batch->received_at ? (string) $batch->received_at : null,
            'finalized_at' => $batch->finalized_at ? (string) $batch->finalized_at : null,
            'purchases' => $linkedPurchases,
            'costs' => $costLines,
        ];
    }

    /**
     * Profitability calculation delegating directly to authoritative ImportBatchProfitabilityService.
     */
    public function batchProfitability(
        AssistantAccessContext|WarehouseScope $context,
        int|string $idOrNumber
    ): ?array {
        if (!$this->isAvailable()) {
            return null;
        }

        $query = ImportBatch::whereNull('deleted_at');

        if (is_numeric($idOrNumber)) {
            $query->where('id', (int) $idOrNumber);
        } else {
            $query->where('batch_number', (string) $idOrNumber);
        }

        $batch = $query->first();

        if (!$batch) {
            return null;
        }

        [$isRestricted, $allowedWarehouseIds, $ownUserId] = $this->resolveScope($context);

        // Fail-closed authorization check
        if ($isRestricted) {
            if (empty($allowedWarehouseIds) || !in_array((int) $batch->warehouse_id, $allowedWarehouseIds, true)) {
                return null;
            }
        }

        if ($ownUserId !== null && (int) $batch->created_by !== (int) $ownUserId) {
            return null;
        }

        $userWarehouseId = $isRestricted && count($allowedWarehouseIds) === 1 ? $allowedWarehouseIds[0] : null;

        $report = $this->profitabilityService->getBatchProfitability(
            $batch,
            $userWarehouseId,
            $isRestricted
        );

        return [
            'batch_id' => (int) $batch->id,
            'batch_number' => (string) $batch->batch_number,
            'title' => (string) $batch->title,
            'status' => (string) $batch->status,
            'summary' => $report['summary'] ?? [],
            'product_breakdown' => $report['product_breakdown'] ?? [],
            'warehouse_breakdown' => $report['warehouse_breakdown'] ?? [],
        ];
    }
}
