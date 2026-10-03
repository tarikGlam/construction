<?php

namespace App\Services\Read;

use App\Models\Supplier;
use App\Services\SupplierDuePaymentService;
use Illuminate\Support\Facades\DB;
use Modules\AIAssistant\DTO\WarehouseScope;
use Modules\AIAssistant\Security\AssistantAccessContext;

class SupplierReadService
{
    public function __construct(
        private SupplierDuePaymentService $supplierDueService
    ) {}

    /**
     * Bounded list of suppliers owed money, respecting warehouse scope.
     *
     * @return array{
     *     total_due: float,
     *     supplier_count: int,
     *     rows: array<array{supplier_id: int, name: string, due: float}>
     * }
     */
    public function dueList(AssistantAccessContext|WarehouseScope $context, int $limit = 10): array
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return [
                'total_due' => 0.0,
                'supplier_count' => 0,
                'rows' => [],
            ];
        }

        if ($ownUserId !== null) {
            return [
                'total_due' => 0.0,
                'supplier_count' => 0,
                'rows' => [],
            ];
        }

        $boundedLimit = max(1, min($limit, 50));
        $warehouseIds = $isRestricted ? $allowedWarehouseIds : null;

        // Candidate suppliers with active status and due purchases / opening balance / returns
        $candidateQuery = DB::table('purchases')
            ->whereNull('deleted_at')
            ->where('status', '!=', 3)
            ->where(function ($q) {
                $q->whereNull('payment_status')
                    ->orWhere('payment_status', '!=', 4);
            });

        if ($warehouseIds !== null) {
            $candidateQuery->whereIn('warehouse_id', $warehouseIds);
        }

        $candidateIds = $candidateQuery->distinct()->pluck('supplier_id');

        if ($warehouseIds === null) {
            $openingIds = DB::table('suppliers')->where('opening_balance', '>', 0)->pluck('id');
            $candidateIds = $candidateIds->merge($openingIds);
        }

        $returnSupplierIds = DB::table('return_purchases')
            ->whereNull('purchase_id')
            ->when($warehouseIds !== null, fn($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->distinct()
            ->pluck('supplier_id');
        $candidateIds = $candidateIds->merge($returnSupplierIds);

        $activeSupplierIds = DB::table('suppliers')
            ->where('is_active', true)
            ->whereIn('id', $candidateIds->filter()->unique())
            ->pluck('id')
            ->all();

        if (empty($activeSupplierIds)) {
            return [
                'total_due' => 0.0,
                'supplier_count' => 0,
                'rows' => [],
            ];
        }

        // Delegate to authoritative SupplierDuePaymentService
        $dues = $this->supplierDueService->duesForSuppliers($activeSupplierIds, $warehouseIds);

        $dueBalances = [];
        foreach ($dues as $sId => $due) {
            if ($due > 0.0001) {
                $dueBalances[$sId] = $due;
            }
        }

        if (empty($dueBalances)) {
            return [
                'total_due' => 0.0,
                'supplier_count' => 0,
                'rows' => [],
            ];
        }

        $totalDue = array_sum($dueBalances);
        $supplierCount = count($dueBalances);

        arsort($dueBalances);

        $pagedSupplierIds = array_slice(array_keys($dueBalances), 0, $boundedLimit, true);
        $supplierNames = DB::table('suppliers')
            ->whereIn('id', $pagedSupplierIds)
            ->pluck('name', 'id')
            ->all();

        $tableRows = [];
        foreach ($pagedSupplierIds as $sId) {
            $tableRows[] = [
                'supplier_id' => (int) $sId,
                'name' => (string) ($supplierNames[$sId] ?? 'Supplier #' . $sId),
                'due' => round((float) $dueBalances[$sId], 2),
            ];
        }

        return [
            'total_due' => round($totalDue, 2),
            'supplier_count' => $supplierCount,
            'rows' => $tableRows,
        ];
    }

    /**
     * Single supplier due balance.
     */
    public function balance(AssistantAccessContext|WarehouseScope $context, int $supplierId): float
    {
        $isRestricted = $context instanceof WarehouseScope ? $context->isRestricted : $context->isRestrictedWarehouseAccess;
        $allowedWarehouseIds = $context instanceof WarehouseScope ? $context->warehouseIds : $context->allowedWarehouseIds;
        $ownUserId = $context->ownUserId;

        if ($isRestricted && empty($allowedWarehouseIds)) {
            return 0.0;
        }

        if ($ownUserId !== null) {
            return 0.0;
        }

        $warehouseIds = $isRestricted ? $allowedWarehouseIds : null;

        return round($this->supplierDueService->dueForSupplier($supplierId, $warehouseIds), 2);
    }
}
