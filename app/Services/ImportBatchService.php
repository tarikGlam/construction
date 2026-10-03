<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\ImportBatchCost;
use App\Models\ImportStockLayer;
use App\Models\ProductPurchase;
use App\Models\Purchase;
use App\Models\Unit;
use App\Services\Accounting\CurrencyNormalizationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportBatchService
{
    public function __construct(
        protected CurrencyNormalizationService $currencyService
    ) {}

    /**
     * Create a new Import Batch
     */
    public function createBatch(array $data, int $userId): ImportBatch
    {
        return DB::transaction(function () use ($data, $userId) {
            $baseCurrencyId = $this->currencyService->getBaseCurrencyId();

            $batchNumber = $data['batch_number'] ?? null;
            if (empty($batchNumber)) {
                $todayPrefix = 'IMP-' . date('Ymd') . '-';
                $lastBatch = ImportBatch::where('batch_number', 'like', $todayPrefix . '%')
                    ->orderBy('id', 'desc')
                    ->first();
                $seq = 1;
                if ($lastBatch && preg_match('/-(\d+)$/', $lastBatch->batch_number, $matches)) {
                    $seq = ((int)$matches[1]) + 1;
                }
                $batchNumber = $todayPrefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
            }

            $batch = ImportBatch::create([
                'batch_number' => $batchNumber,
                'reference_no' => $data['reference_no'] ?? null,
                'title' => $data['title'] ?? ('Import Container ' . $batchNumber),
                'warehouse_id' => $data['warehouse_id'] ?? $data['target_warehouse_id'] ?? null,
                'base_currency_id' => $baseCurrencyId,
                'status' => 'draft',
                'is_locked' => false,
                'allocation_method' => $data['allocation_method'] ?? 'purchase_value',
                'total_goods_cost' => '0.0000',
                'total_landed_cost' => '0.0000',
                'total_cost' => '0.0000',
                'notes' => $data['notes'] ?? null,
                'received_at' => !empty($data['received_at']) ? Carbon::parse($data['received_at']) : Carbon::now(),
                'created_by' => $userId,
            ]);

            if (!empty($data['purchase_ids']) && is_array($data['purchase_ids'])) {
                $this->linkPurchases($batch, $data['purchase_ids']);
            }

            return $batch;
        });
    }

    /**
     * Update an Import Batch
     */
    public function updateBatch(ImportBatch $batch, array $data): ImportBatch
    {
        $this->assertNotLocked($batch);

        return DB::transaction(function () use ($batch, $data) {
            if (isset($data['warehouse_id']) && (int)$data['warehouse_id'] !== (int)$batch->warehouse_id) {
                // If purchases are already linked, ensure they can move or forbid warehouse change
                $linkedCount = $batch->purchases()->count();
                if ($linkedCount > 0) {
                    throw ValidationException::withMessages([
                        'warehouse_id' => 'Cannot change warehouse while purchases are linked to this import batch. Unlink purchases first.'
                    ]);
                }
            }

            $batch->update([
                'reference_no' => $data['reference_no'] ?? $batch->reference_no,
                'title' => $data['title'] ?? $batch->title,
                'warehouse_id' => $data['warehouse_id'] ?? $batch->warehouse_id,
                'allocation_method' => $data['allocation_method'] ?? $batch->allocation_method,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $batch->notes,
                'received_at' => !empty($data['received_at']) ? Carbon::parse($data['received_at']) : $batch->received_at,
            ]);

            if (isset($data['purchase_ids']) && is_array($data['purchase_ids'])) {
                $this->syncPurchases($batch, $data['purchase_ids']);
            }

            $this->recalculateBatchTotals($batch);

            return $batch->fresh();
        });
    }

    /**
     * Link purchases to the import batch with strict warehouse validation
     */
    public function linkPurchases(ImportBatch $batch, array $purchaseIds): void
    {
        $this->assertNotLocked($batch);

        if (empty($purchaseIds)) {
            return;
        }

        $purchases = Purchase::whereIn('id', $purchaseIds)->get();

        foreach ($purchases as $purchase) {
            if ((int)$purchase->warehouse_id !== (int)$batch->warehouse_id) {
                $warehouseName = $batch->warehouse ? $batch->warehouse->name : "ID {$batch->warehouse_id}";
                throw ValidationException::withMessages([
                    'purchase_ids' => "Purchase #{$purchase->reference_no} belongs to a different warehouse. All linked purchases must belong to the import batch receiving warehouse ({$warehouseName})."
                ]);
            }

            if ($purchase->import_batch_id && (int)$purchase->import_batch_id !== (int)$batch->id) {
                throw ValidationException::withMessages([
                    'purchase_ids' => "Purchase #{$purchase->reference_no} is already linked to another import batch."
                ]);
            }
        }

        Purchase::whereIn('id', $purchaseIds)->update(['import_batch_id' => $batch->id]);

        $this->recalculateBatchTotals($batch);
    }

    /**
     * Sync purchases linked to batch
     */
    public function syncPurchases(ImportBatch $batch, array $purchaseIds): void
    {
        $this->assertNotLocked($batch);

        // Unlink purchases no longer in list
        Purchase::where('import_batch_id', $batch->id)
            ->whereNotIn('id', $purchaseIds)
            ->update(['import_batch_id' => null]);

        $this->linkPurchases($batch, $purchaseIds);
    }

    /**
     * Unlink a purchase from the import batch
     */
    public function unlinkPurchase(ImportBatch $batch, int $purchaseId): void
    {
        $this->assertNotLocked($batch);

        Purchase::where('id', $purchaseId)
            ->where('import_batch_id', $batch->id)
            ->update(['import_batch_id' => null]);

        $this->recalculateBatchTotals($batch);
    }

    /**
     * Add a landed cost line to the batch
     */
    public function addCost(ImportBatch $batch, array $data): ImportBatchCost
    {
        $this->assertNotLocked($batch);

        $currencyId = (int)($data['currency_id'] ?? $batch->base_currency_id);
        $originalAmount = (string)($data['original_amount'] ?? 0);
        $exchangeRate = (string)($data['exchange_rate'] ?? 1);

        // SalePro's accounting convention: base = transaction amount / exchange rate.
        if ($currencyId === (int)$batch->base_currency_id) {
            $exchangeRate = '1.00000000';
            $baseAmount = number_format((float)$originalAmount, 4, '.', '');
        } else {
            $baseAmount = $this->currencyService->normalize($originalAmount, $currencyId, $exchangeRate);
        }

        $cost = ImportBatchCost::create([
            'import_batch_id' => $batch->id,
            'cost_type' => $data['cost_type'],
            'original_amount' => $originalAmount,
            'currency_id' => $currencyId,
            'exchange_rate' => $exchangeRate,
            'base_amount' => $baseAmount,
            'vendor_id' => $data['vendor_id'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_posted_to_accounts' => false,
        ]);

        $this->recalculateBatchTotals($batch);

        return $cost;
    }

    /**
     * Update an existing landed cost line
     */
    public function updateCost(ImportBatchCost $cost, array $data): ImportBatchCost
    {
        $batch = $cost->importBatch;
        $this->assertNotLocked($batch);

        $currencyId = isset($data['currency_id']) ? (int)$data['currency_id'] : (int)$cost->currency_id;
        $originalAmount = isset($data['original_amount']) ? (string)$data['original_amount'] : (string)$cost->original_amount;
        $exchangeRate = isset($data['exchange_rate']) ? (string)$data['exchange_rate'] : (string)$cost->exchange_rate;

        if ($currencyId === (int)$batch->base_currency_id) {
            $exchangeRate = '1.00000000';
            $baseAmount = number_format((float)$originalAmount, 4, '.', '');
        } else {
            $baseAmount = $this->currencyService->normalize($originalAmount, $currencyId, $exchangeRate);
        }

        $cost->update([
            'cost_type' => $data['cost_type'] ?? $cost->cost_type,
            'original_amount' => $originalAmount,
            'currency_id' => $currencyId,
            'exchange_rate' => $exchangeRate,
            'base_amount' => $baseAmount,
            'vendor_id' => array_key_exists('vendor_id', $data) ? $data['vendor_id'] : $cost->vendor_id,
            'reference_no' => array_key_exists('reference_no', $data) ? $data['reference_no'] : $cost->reference_no,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $cost->notes,
        ]);

        $this->recalculateBatchTotals($batch);

        return $cost;
    }

    /**
     * Delete a landed cost line
     */
    public function deleteCost(ImportBatchCost $cost): void
    {
        $batch = $cost->importBatch;
        $this->assertNotLocked($batch);

        $cost->delete();

        $this->recalculateBatchTotals($batch);
    }

    /**
     * Recalculate total goods cost, landed cost, and total combined cost in base currency
     */
    public function recalculateBatchTotals(ImportBatch $batch): void
    {
        $linkedPurchases = Purchase::where('import_batch_id', $batch->id)->get();

        $totalGoodsCost = '0.0000';
        foreach ($linkedPurchases as $p) {
            // Normalize purchase total to base currency
            $rate = (string)($p->exchange_rate ?? 1);
            if ((int)$p->currency_id === (int)$batch->base_currency_id || bccomp($rate, '0', 4) <= 0) {
                $basePurchaseTotal = (string)$p->grand_total;
            } else {
                // SalePro purchase rate convention: base = grand_total / exchange_rate
                $basePurchaseTotal = bcdiv((string)$p->grand_total, $rate, 4);
            }
            $totalGoodsCost = bcadd($totalGoodsCost, $basePurchaseTotal, 4);
        }

        $totalLandedCost = (string)ImportBatchCost::where('import_batch_id', $batch->id)
            ->sum('base_amount');

        $totalCost = bcadd($totalGoodsCost, $totalLandedCost, 4);

        $batch->update([
            'total_goods_cost' => $totalGoodsCost,
            'total_landed_cost' => $totalLandedCost,
            'total_cost' => $totalCost,
        ]);
    }

    /**
     * Calculate Landed Cost Allocation per ProductPurchase line with Penny-Exact rounding reconciliation
     */
    public function calculateAllocations(ImportBatch $batch, ?string $method = null, ?array $manualAllocations = null): array
    {
        $method = $method ?: ($batch->allocation_method ?: 'purchase_value');
        $totalLandedCost = (float)$batch->total_landed_cost;

        $linkedPurchaseIds = Purchase::where('import_batch_id', $batch->id)->pluck('id');
        $productPurchases = ProductPurchase::whereIn('purchase_id', $linkedPurchaseIds)
            ->with(['purchase', 'product'])
            ->get();

        if ($productPurchases->isEmpty()) {
            return [];
        }

        $lines = [];
        $totalWeight = 0.0;

        foreach ($productPurchases as $line) {
            if (!$line->product || in_array($line->product->type, ['combo', 'digital', 'service'], true)) {
                throw ValidationException::withMessages([
                    'purchase_ids' => 'Import costing requires physical purchased product lines; combo, digital, and service products cannot create stock layers.',
                ]);
            }
            if ((float) $line->return_qty > 0.00001) {
                throw ValidationException::withMessages([
                    'purchase_ids' => 'A purchase already returned to the supplier cannot be finalized as a new import batch.',
                ]);
            }
            $purchaseQty = (float)($line->qty ?? 0);
            $qty = $purchaseQty;
            $purchaseUnit = Unit::find($line->purchase_unit_id);
            if ($purchaseUnit && (float) $purchaseUnit->operation_value > 0) {
                $qty = $purchaseUnit->operator === '*'
                    ? $purchaseQty * (float) $purchaseUnit->operation_value
                    : $purchaseQty / (float) $purchaseUnit->operation_value;
            }
            $unitCost = (float)($line->net_unit_cost ?? 0);
            $purchase = $line->purchase;

            // Purchase exchange rate
            $pRate = (float)($purchase->exchange_rate ?? 1);
            if ((int)$purchase->currency_id === (int)$batch->base_currency_id || $pRate <= 0) {
                $baseUnitCost = $unitCost;
            } else {
                $baseUnitCost = $unitCost / $pRate;
            }

            $baseLineTotal = $baseUnitCost * $purchaseQty;
            $baseUnitCost = $qty > 0 ? $baseLineTotal / $qty : 0;

            if ($method === 'quantity') {
                $weight = $qty;
            } elseif ($method === 'manual' && isset($manualAllocations[$line->id])) {
                $weight = (float)$manualAllocations[$line->id];
            } else {
                // purchase_value
                $weight = $baseLineTotal;
            }

            $totalWeight += $weight;

            $lines[$line->id] = [
                'line_id' => $line->id,
                'purchase_id' => $line->purchase_id,
                'purchase_ref' => $purchase->reference_no,
                'product_id' => $line->product_id,
                'product_name' => $line->product ? $line->product->name : 'Item #' . $line->product_id,
                'product_code' => $line->product ? $line->product->code : '',
                'variant_id' => $line->variant_id,
                'product_batch_id' => $line->product_batch_id,
                'qty' => $qty,
                'base_unit_purchase_cost' => round($baseUnitCost, 4),
                'base_purchase_total' => round($baseLineTotal, 4),
                'weight' => $weight,
                'allocated_landed_cost' => 0.0,
                'unit_landed_cost' => 0.0,
                'total_unit_cost' => 0.0,
            ];
        }

        // Reconcile purchase-level tax, discount and shipping into the authoritative goods total.
        // The unit value remains a display value; layer amounts carry the exact allocation.
        $lineGoodsTotal = round(array_sum(array_column($lines, 'base_purchase_total')), 4);
        $goodsResidual = round((float) $batch->total_goods_cost - $lineGoodsTotal, 4);
        if (abs($goodsResidual) > 0.00001) {
            $largestId = array_key_first($lines);
            foreach ($lines as $id => $candidate) {
                if ($candidate['base_purchase_total'] > $lines[$largestId]['base_purchase_total']) {
                    $largestId = $id;
                }
            }
            $lines[$largestId]['base_purchase_total'] = round($lines[$largestId]['base_purchase_total'] + $goodsResidual, 4);
            $lines[$largestId]['base_unit_purchase_cost'] = $lines[$largestId]['qty'] > 0
                ? round($lines[$largestId]['base_purchase_total'] / $lines[$largestId]['qty'], 4) : 0;
            if ($method === 'purchase_value') {
                $lines[$largestId]['weight'] += $goodsResidual;
                $totalWeight += $goodsResidual;
            }
        }

        if ($totalLandedCost <= 0.0 || $totalWeight <= 0.0) {
            foreach ($lines as $id => $item) {
                $lines[$id]['unit_landed_cost'] = 0.0;
                $lines[$id]['total_unit_cost'] = $lines[$id]['base_unit_purchase_cost'];
            }
            return array_values($lines);
        }

        // Pro-rata distribution
        $sumAllocated = 0.0;
        $maxWeightLineId = null;
        $maxWeight = -1.0;

        foreach ($lines as $id => $item) {
            $proportion = $item['weight'] / $totalWeight;
            $allocated = round($totalLandedCost * $proportion, 4);
            $lines[$id]['allocated_landed_cost'] = $allocated;
            $sumAllocated += $allocated;

            if ($item['weight'] > $maxWeight) {
                $maxWeight = $item['weight'];
                $maxWeightLineId = $id;
            }
        }

        // Penny-exact rounding reconciliation: add residual fraction to line with largest weight
        $residual = round($totalLandedCost - $sumAllocated, 4);
        if (abs($residual) > 0.00001 && $maxWeightLineId !== null) {
            $lines[$maxWeightLineId]['allocated_landed_cost'] = round(
                $lines[$maxWeightLineId]['allocated_landed_cost'] + $residual,
                4
            );
        }

        // Compute unit costs
        foreach ($lines as $id => $item) {
            $allocated = $lines[$id]['allocated_landed_cost'];
            $qty = $lines[$id]['qty'];
            $unitLanded = $qty > 0 ? round($allocated / $qty, 4) : 0.0;
            $lines[$id]['unit_landed_cost'] = $unitLanded;
            $lines[$id]['total_unit_cost'] = round($lines[$id]['base_unit_purchase_cost'] + $unitLanded, 4);
        }

        return array_values($lines);
    }

    /**
     * Finalize the Import Batch and create initial ImportStockLayer records
     */
    public function finalizeBatch(ImportBatch $batch, ?string $allocationMethod = null, ?array $manualAllocations = null): ImportBatch
    {
        if ($batch->status !== 'draft') {
            throw ValidationException::withMessages(['import_batch' => 'Only a draft import batch can be finalized.']);
        }
        $this->assertNotLocked($batch);

        $purchasesCount = Purchase::where('import_batch_id', $batch->id)->count();
        if ($purchasesCount === 0) {
            throw ValidationException::withMessages([
                'purchase_ids' => 'Cannot finalize an import batch with no linked purchases.'
            ]);
        }

        return DB::transaction(function () use ($batch, $allocationMethod, $manualAllocations) {
            $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'draft') {
                throw ValidationException::withMessages(['import_batch' => 'Only a draft import batch can be finalized.']);
            }
            if ($allocationMethod) {
                $batch->allocation_method = $allocationMethod;
            }

            $this->recalculateBatchTotals($batch);

            $allocations = $this->calculateAllocations($batch, $batch->allocation_method, $manualAllocations);

            // Clear any previous draft layers for this batch
            ImportStockLayer::where('import_batch_id', $batch->id)->delete();

            // Create initial stock layers in the receiving warehouse
            foreach ($allocations as $item) {
                ImportStockLayer::create([
                    'import_batch_id' => $batch->id,
                    'purchase_id' => $item['purchase_id'],
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'product_batch_id' => $item['product_batch_id'],
                    'warehouse_id' => $batch->warehouse_id,
                    'received_qty' => $item['qty'],
                    'remaining_qty' => $item['qty'],
                    'unit_purchase_cost' => $item['base_unit_purchase_cost'],
                    'unit_landed_cost' => $item['unit_landed_cost'],
                    'total_unit_cost' => $item['total_unit_cost'],
                    'goods_base_amount' => $item['base_purchase_total'],
                    'allocated_landed_amount' => $item['allocated_landed_cost'],
                    'remaining_goods_amount' => $item['base_purchase_total'],
                    'remaining_landed_amount' => $item['allocated_landed_cost'],
                    'currency_id' => $batch->base_currency_id,
                    'source_type' => 'purchase',
                    'source_id' => $item['line_id'],
                ]);
            }

            $batch->status = 'finalized';
            $batch->finalized_at = Carbon::now();
            $batch->save();

            return $batch->fresh();
        });
    }

    /**
     * Reopen/unlock a finalized batch if no stock has been consumed
     */
    public function reopenBatch(ImportBatch $batch): ImportBatch
    {
        $this->assertNotLocked($batch);

        return DB::transaction(function () use ($batch) {
            ImportStockLayer::where('import_batch_id', $batch->id)->delete();
            $batch->status = 'draft';
            $batch->finalized_at = null;
            $batch->save();

            return $batch->fresh();
        });
    }

    /**
     * Assert batch is not locked due to stock consumption
     */
    public function assertNotLocked(ImportBatch $batch): void
    {
        if ($batch->is_locked) {
            throw ValidationException::withMessages([
                'import_batch' => "Import batch #{$batch->batch_number} is locked because stock has already been consumed or transferred."
            ]);
        }

        // Double check layers to ensure no partial consumption occurred
        $consumed = ImportStockLayer::where('import_batch_id', $batch->id)
            ->whereRaw('remaining_qty < received_qty')
            ->exists();

        if ($consumed) {
            $batch->update(['is_locked' => true]);
            throw ValidationException::withMessages([
                'import_batch' => "Import batch #{$batch->batch_number} is locked because stock has already been consumed or transferred."
            ]);
        }
    }
}
