<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\AdjustmentImportCostAllocation;
use App\Models\ImportStockLayer;
use App\Models\Product;
use App\Models\ProductAdjustment;
use App\Models\ProductPurchase;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturnImportCostAllocation;
use App\Models\ProductReturn;
use App\Models\ProductTransfer;
use App\Models\Product_Sale;
use App\Models\ReturnImportCostAllocation;
use App\Models\ReturnPurchase;
use App\Models\Returns;
use App\Models\Sale;
use App\Models\SaleImportCostAllocation;
use App\Models\Transfer;
use App\Models\TransferImportCostAllocation;
use App\Services\Accounting\CurrencyNormalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportCostAllocationService
{
    public function __construct(
        protected CurrencyNormalizationService $currencyService
    ) {}

    /** Split the remaining authoritative amounts; the final movement takes the exact residual. */
    private function consumeLayer(ImportStockLayer $layer, float $quantity): array
    {
        $available = (float) $layer->remaining_qty;
        if ($quantity <= 0 || $quantity > $available + 0.00001) {
            throw ValidationException::withMessages(['stock' => 'Imported stock layer quantity is insufficient.']);
        }
        $all = abs($quantity - $available) < 0.00001;
        $goods = $all ? (float) $layer->remaining_goods_amount
            : round((float) $layer->remaining_goods_amount * $quantity / $available, 4);
        $landed = $all ? (float) $layer->remaining_landed_amount
            : round((float) $layer->remaining_landed_amount * $quantity / $available, 4);
        $layer->remaining_qty = round($available - $quantity, 4);
        $layer->remaining_goods_amount = round((float) $layer->remaining_goods_amount - $goods, 4);
        $layer->remaining_landed_amount = round((float) $layer->remaining_landed_amount - $landed, 4);
        $layer->save();
        return [$goods, $landed];
    }

    public function restoreLayer(ImportStockLayer $layer, float $quantity, float $cost): void
    {
        $unitGoods = (float) $layer->goods_base_amount;
        $unitLanded = (float) $layer->allocated_landed_amount;
        $ratio = $unitGoods + $unitLanded > 0 ? $unitGoods / ($unitGoods + $unitLanded) : 1;
        $goods = round($cost * $ratio, 4);
        $this->restoreLayerAmounts($layer, $quantity, $goods, round($cost - $goods, 4));
    }

    public function restoreLayerAmounts(ImportStockLayer $layer, float $quantity, float $goods, float $landed): void
    {
        if ((float) $layer->remaining_qty + $quantity > (float) $layer->received_qty + 0.00001) {
            throw ValidationException::withMessages(['stock' => 'Imported stock layer reversal exceeds received quantity.']);
        }
        $layer->remaining_qty = round((float) $layer->remaining_qty + $quantity, 4);
        $layer->remaining_goods_amount = round((float) $layer->remaining_goods_amount + $goods, 4);
        $layer->remaining_landed_amount = round((float) $layer->remaining_landed_amount + $landed, 4);
        if (abs((float) $layer->remaining_qty - (float) $layer->received_qty) < 0.00001) {
            $layer->remaining_goods_amount = $layer->goods_base_amount;
            $layer->remaining_landed_amount = $layer->allocated_landed_amount;
        }
        $layer->save();
    }

    /** Remove stock returned to the supplier from its original purchase layer. */
    public function allocatePurchaseReturnCost(ReturnPurchase $return, PurchaseProductReturn $line, ProductPurchase $purchaseLine): void
    {
        if (PurchaseReturnImportCostAllocation::where('purchase_product_return_id', $line->id)->exists()) {
            return;
        }
        $purchase = $return->purchase;
        if (!$purchase || !$purchase->import_batch_id) {
            return;
        }
        if ($purchase->importBatch?->status !== 'finalized') {
            throw ValidationException::withMessages(['purchase_id' => 'Finalize import costing before returning imported stock to the supplier.']);
        }
        $unit = \App\Models\Unit::find($line->purchase_unit_id);
        $quantity = (float) $line->qty;
        if ($unit) {
            $quantity = $unit->operator === '*' ? $quantity * (float) $unit->operation_value
                : $quantity / (float) $unit->operation_value;
        }
        $layers = ImportStockLayer::where('purchase_id', $purchase->id)
            ->where('source_type', 'purchase')->where('source_id', $purchaseLine->id)
            ->where('warehouse_id', $return->warehouse_id)
            ->where('product_id', $line->product_id)
            ->where('variant_id', $line->variant_id ?: null)
            ->where('product_batch_id', $line->product_batch_id ?: null)
            ->orderBy('id')->lockForUpdate()->get();
        if ($layers->sum('remaining_qty') + 0.00001 < $quantity) {
            throw ValidationException::withMessages(['stock' => 'The original imported purchase has insufficient unsold stock for this supplier return.']);
        }
        $remaining = $quantity;
        foreach ($layers as $layer) {
            if ($remaining <= 0.00001) {
                break;
            }
            $take = min($remaining, (float) $layer->remaining_qty);
            [$goods, $landed] = $this->consumeLayer($layer, $take);
            $layer->received_qty = round((float) $layer->received_qty - $take, 4);
            $layer->goods_base_amount = round((float) $layer->goods_base_amount - $goods, 4);
            $layer->allocated_landed_amount = round((float) $layer->allocated_landed_amount - $landed, 4);
            $layer->save();
            PurchaseReturnImportCostAllocation::create([
                'return_purchase_id' => $return->id,
                'purchase_product_return_id' => $line->id,
                'import_stock_layer_id' => $layer->id,
                'returned_qty' => $take,
                'goods_cost' => $goods,
                'landed_cost' => $landed,
            ]);
            $remaining = round($remaining - $take, 4);
        }
        $this->syncPurchaseReturnQuantity($purchaseLine);
    }

    public function reversePurchaseReturnCost(ReturnPurchase $return): void
    {
        $allocations = PurchaseReturnImportCostAllocation::where('return_purchase_id', $return->id)
            ->orderBy('id')->lockForUpdate()->get();
        $purchaseLineIds = [];
        foreach ($allocations as $allocation) {
            $layer = ImportStockLayer::whereKey($allocation->import_stock_layer_id)->lockForUpdate()->firstOrFail();
            $purchaseLineIds[] = (int) $layer->source_id;
            $layer->received_qty = round((float) $layer->received_qty + (float) $allocation->returned_qty, 4);
            $layer->remaining_qty = round((float) $layer->remaining_qty + (float) $allocation->returned_qty, 4);
            $layer->goods_base_amount = round((float) $layer->goods_base_amount + (float) $allocation->goods_cost, 4);
            $layer->allocated_landed_amount = round((float) $layer->allocated_landed_amount + (float) $allocation->landed_cost, 4);
            $layer->remaining_goods_amount = round((float) $layer->remaining_goods_amount + (float) $allocation->goods_cost, 4);
            $layer->remaining_landed_amount = round((float) $layer->remaining_landed_amount + (float) $allocation->landed_cost, 4);
            $layer->save();
            $allocation->delete();
        }
        foreach (array_unique($purchaseLineIds) as $purchaseLineId) {
            $this->syncPurchaseReturnQuantity(ProductPurchase::findOrFail($purchaseLineId));
        }
    }

    private function syncPurchaseReturnQuantity(ProductPurchase $purchaseLine): void
    {
        $baseQuantity = (float) DB::table('purchase_return_import_cost_allocations as allocation')
            ->join('import_stock_layers as layer', 'layer.id', '=', 'allocation.import_stock_layer_id')
            ->where('layer.source_type', 'purchase')
            ->where('layer.source_id', $purchaseLine->id)
            ->sum('allocation.returned_qty');
        $unit = \App\Models\Unit::find($purchaseLine->purchase_unit_id);
        $quantity = $unit && (float) $unit->operation_value > 0
            ? ($unit->operator === '*' ? $baseQuantity / (float) $unit->operation_value
                : $baseQuantity * (float) $unit->operation_value)
            : $baseQuantity;
        $purchaseLine->return_qty = round($quantity, 4);
        $purchaseLine->save();
    }

    /**
     * Allocate imported landed stock layers to a Sale line using FIFO in the sale warehouse.
     */
    public function allocateSaleCost(Sale $sale, Product_Sale $productSale, float $qtyToAllocate, ?float $saleUnitQty = null): array
    {
        if ($qtyToAllocate <= 0) {
            return [];
        }
        if (in_array(Product::whereKey($productSale->product_id)->value('type'), ['combo', 'digital', 'service'], true)) {
            return [];
        }

        $baseCurrencyId = $this->currencyService->getBaseCurrencyId();

        // Persist net attributable revenue at sale time, including the invoice discount.
        // SalePro's net_unit_price already reflects the line discount; total_price includes tax.
        $saleRate = (float)($sale->exchange_rate ?? 1);
        $rawUnitPrice = (float)($productSale->net_unit_price ?? 0);
        $saleLineQty = $saleUnitQty ?? (float) $productSale->qty;
        $lineRevenue = $rawUnitPrice * $saleLineQty;
        $invoiceRevenueBeforeDiscount = max(0.0, (float) $sale->total_price - (float) $sale->total_tax);
        $invoiceDiscount = (float) $sale->order_discount + (float) $sale->coupon_discount;
        if ($invoiceRevenueBeforeDiscount > 0 && $invoiceDiscount > 0) {
            $lineRevenue -= $invoiceDiscount * $lineRevenue / $invoiceRevenueBeforeDiscount;
        }
        $lineRevenue = max(0.0, $lineRevenue);
        if ((int)$sale->currency_id === $baseCurrencyId || $saleRate <= 0) {
            $baseLineRevenue = $lineRevenue;
        } else {
            $baseLineRevenue = $lineRevenue / $saleRate;
        }
        $baseSellingPrice = $baseLineRevenue / $qtyToAllocate;

        $allocations = [];
        $remainingNeeded = $qtyToAllocate;

        // Query layers in the sale warehouse for this product and variant ordered FIFO
        $layersQuery = ImportStockLayer::where('warehouse_id', $sale->warehouse_id)
            ->where('product_id', $productSale->product_id)
            ->where('remaining_qty', '>', 0)
            ->orderBy('id', 'asc')
            ->lockForUpdate();

        if (!empty($productSale->variant_id)) {
            $layersQuery->where('variant_id', $productSale->variant_id);
        } else {
            $layersQuery->whereNull('variant_id');
        }

        $layersQuery->where('product_batch_id', $productSale->product_batch_id ?: null);

        $availableLayers = $layersQuery->get();

        foreach ($availableLayers as $layer) {
            if ($remainingNeeded <= 0.00001) {
                break;
            }

            $avail = (float)$layer->remaining_qty;
            $consumedQty = min($remainingNeeded, $avail);

            // Decrement remaining qty
            [$goods, $landed] = $this->consumeLayer($layer, $consumedQty);

            // Lock parent batch
            if ($layer->importBatch && !$layer->importBatch->is_locked) {
                $layer->importBatch()->update(['is_locked' => true]);
            }

            $unitLandedCost = (float)$layer->total_unit_cost;
            $totalCost = round($goods + $landed, 4);
            $revenue = round($consumedQty * $baseSellingPrice, 4);
            $realizedProfit = round($revenue - $totalCost, 4);

            $allocation = SaleImportCostAllocation::create([
                'sale_id' => $sale->id,
                'product_sale_id' => $productSale->id,
                'import_batch_id' => $layer->import_batch_id,
                'import_stock_layer_id' => $layer->id,
                'warehouse_id' => $sale->warehouse_id,
                'product_id' => $productSale->product_id,
                'variant_id' => $productSale->variant_id,
                'allocated_qty' => $consumedQty,
                'unit_landed_cost' => $unitLandedCost,
                'total_cost' => $totalCost,
                'goods_cost' => $goods,
                'landed_cost' => $landed,
                'selling_price' => round($baseSellingPrice, 4),
                'revenue' => $revenue,
                'realized_gross_profit' => $realizedProfit,
                'currency_id' => $baseCurrencyId,
            ]);

            $allocations[] = $allocation;
            $remainingNeeded = round($remainingNeeded - $consumedQty, 4);
        }

        return $allocations;
    }

    /**
     * Reverse sale allocations when a sale is deleted or voided
     */
    public function reverseSaleCost(Sale $sale): void
    {
        if (ReturnImportCostAllocation::whereHas('saleImportCostAllocation', fn ($query) => $query->where('sale_id', $sale->id))->exists()) {
            throw ValidationException::withMessages(['sale' => 'A linked import return exists; reverse the return before deleting the sale.']);
        }
        $allocations = SaleImportCostAllocation::where('sale_id', $sale->id)->get();

        foreach ($allocations as $alloc) {
            $alreadyReturned = (float)ReturnImportCostAllocation::where('sale_import_cost_allocation_id', $alloc->id)->sum('returned_qty');
            $netToRestore = max(0.0, (float)$alloc->allocated_qty - $alreadyReturned);

            $layer = ImportStockLayer::find($alloc->import_stock_layer_id);
            if (!$layer) {
                throw ValidationException::withMessages(['sale' => 'Imported stock provenance is missing.']);
            }
            if ($netToRestore > 0.00001) {
                $this->restoreLayerAmounts($layer, $netToRestore, (float) $alloc->goods_cost, (float) $alloc->landed_cost);
            }
            $alloc->delete();
        }
    }

    /**
     * Restore stock to import layer on customer return (LIFO reversal)
     */
    public function allocateReturnCost(Returns $return, ProductReturn $productReturn, ?int $originalSaleId = null): array
    {
        $existing = ReturnImportCostAllocation::where('product_return_id', $productReturn->id)->get();
        if ($existing->isNotEmpty()) {
            return $existing->all();
        }
        $returnQty = (float)($productReturn->qty ?? 0);
        $returnUnit = \App\Models\Unit::find($productReturn->sale_unit_id);
        if ($returnUnit) {
            $returnQty = $returnUnit->operator === '*'
                ? $returnQty * (float) $returnUnit->operation_value
                : $returnQty / (float) $returnUnit->operation_value;
        }
        if ($returnQty <= 0) {
            return [];
        }

        $allocationsRestored = [];
        $remainingToRestore = $returnQty;

        $saleAllocationsQuery = SaleImportCostAllocation::where('warehouse_id', $return->warehouse_id)
            ->where('product_id', $productReturn->product_id);

        if (!$originalSaleId) {
            return [];
        }
        $saleAllocationsQuery->where('sale_id', $originalSaleId);
        if ($productReturn->product_sale_id) {
            $saleAllocationsQuery->where('product_sale_id', $productReturn->product_sale_id);
        }

        if (!empty($productReturn->variant_id)) {
            $saleAllocationsQuery->where('variant_id', $productReturn->variant_id);
        } else {
            $saleAllocationsQuery->whereNull('variant_id');
        }
        $saleAllocationsQuery->whereHas('productSale', function ($query) use ($productReturn) {
            $query->where('product_batch_id', $productReturn->product_batch_id ?: null);
        });

        // LIFO order for returning
        $saleAllocations = $saleAllocationsQuery->orderBy('id', 'desc')->get();
        if (!$productReturn->product_sale_id && $saleAllocations->pluck('product_sale_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['product_sale_id' => 'The original sale line is required to attribute this import return.']);
        }

        foreach ($saleAllocations as $saleAlloc) {
            if ($remainingToRestore <= 0.00001) {
                break;
            }

            $layer = ImportStockLayer::find($saleAlloc->import_stock_layer_id);
            if (!$layer) {
                continue;
            }

            // Cap restore by layer's initial received_qty
            $canRestoreToLayer = max(0.0, (float)$layer->received_qty - (float)$layer->remaining_qty);
            if ($canRestoreToLayer <= 0.00001) {
                continue;
            }

            $alreadyReturned = (float)ReturnImportCostAllocation::where('sale_import_cost_allocation_id', $saleAlloc->id)->sum('returned_qty');
            $availableToReturnOnAlloc = max(0.0, (float)$saleAlloc->allocated_qty - $alreadyReturned);
            if ($availableToReturnOnAlloc <= 0.00001) {
                continue;
            }

            $restoreQty = min($remainingToRestore, $canRestoreToLayer, $availableToReturnOnAlloc);
            $unitCost = (float)$layer->total_unit_cost;
            $last = abs($restoreQty - $availableToReturnOnAlloc) < 0.00001;
            $alreadyCost = (float) ReturnImportCostAllocation::where('sale_import_cost_allocation_id', $saleAlloc->id)->sum('total_cost_restored');
            $alreadyRevenue = (float) ReturnImportCostAllocation::where('sale_import_cost_allocation_id', $saleAlloc->id)->sum('revenue_reversed');
            $totalCostRestored = $last ? round((float) $saleAlloc->total_cost - $alreadyCost, 4)
                : round((float) $saleAlloc->total_cost * $restoreQty / (float) $saleAlloc->allocated_qty, 4);
            $alreadyGoods = (float) ReturnImportCostAllocation::where('sale_import_cost_allocation_id', $saleAlloc->id)->sum('goods_cost_restored');
            $alreadyLanded = (float) ReturnImportCostAllocation::where('sale_import_cost_allocation_id', $saleAlloc->id)->sum('landed_cost_restored');
            $goodsRestored = $last ? round((float) $saleAlloc->goods_cost - $alreadyGoods, 4)
                : round((float) $saleAlloc->goods_cost * $restoreQty / (float) $saleAlloc->allocated_qty, 4);
            $landedRestored = $last ? round((float) $saleAlloc->landed_cost - $alreadyLanded, 4)
                : round($totalCostRestored - $goodsRestored, 4);
            $revenueReversed = $last ? round((float) $saleAlloc->revenue - $alreadyRevenue, 4)
                : round((float) $saleAlloc->revenue * $restoreQty / (float) $saleAlloc->allocated_qty, 4);
            $this->restoreLayerAmounts($layer, $restoreQty, $goodsRestored, $landedRestored);

            $retAlloc = ReturnImportCostAllocation::create([
                'return_id' => $return->id,
                'product_return_id' => $productReturn->id,
                'sale_import_cost_allocation_id' => $saleAlloc->id,
                'import_stock_layer_id' => $layer->id,
                'returned_qty' => $restoreQty,
                'unit_cost_restored' => $unitCost,
                'total_cost_restored' => $totalCostRestored,
                'goods_cost_restored' => $goodsRestored,
                'landed_cost_restored' => $landedRestored,
                'revenue_reversed' => $revenueReversed,
            ]);

            $allocationsRestored[] = $retAlloc;
            $remainingToRestore = round($remainingToRestore - $restoreQty, 4);
        }

        return $allocationsRestored;
    }

    /** Remove a return's restoration before that return is edited or deleted. */
    public function reverseReturnCost(Returns $return): void
    {
        $allocations = ReturnImportCostAllocation::where('return_id', $return->id)
            ->orderByDesc('id')->lockForUpdate()->get();
        foreach ($allocations as $allocation) {
            $layer = ImportStockLayer::whereKey($allocation->import_stock_layer_id)->lockForUpdate()->first();
            if (!$layer || (float) $layer->remaining_qty + 0.00001 < (float) $allocation->returned_qty
                || (float) $layer->remaining_goods_amount + 0.00001 < (float) $allocation->goods_cost_restored
                || (float) $layer->remaining_landed_amount + 0.00001 < (float) $allocation->landed_cost_restored) {
                throw ValidationException::withMessages(['return' => 'Returned imported stock has been consumed and cannot be reversed.']);
            }
            $layer->remaining_qty = round((float) $layer->remaining_qty - (float) $allocation->returned_qty, 4);
            $layer->remaining_goods_amount = round((float) $layer->remaining_goods_amount - (float) $allocation->goods_cost_restored, 4);
            $layer->remaining_landed_amount = round((float) $layer->remaining_landed_amount - (float) $allocation->landed_cost_restored, 4);
            $layer->save();
            $allocation->delete();
        }
    }

    /**
     * Transfer imported stock between warehouses preserving provenance and landed cost
     */
    public function allocateTransferCost(Transfer $transfer, ProductTransfer $productTransfer): array
    {
        $unit = $productTransfer->unit;
        $transferQty = (float)($productTransfer->qty ?? 0);
        if ($unit) {
            $transferQty = $unit->operator === '*'
                ? $transferQty * (float) $unit->operation_value
                : $transferQty / (float) $unit->operation_value;
        }
        if ($transferQty <= 0) {
            return [];
        }

        $allocations = [];
        $remainingNeeded = $transferQty;

        $sourceLayersQuery = ImportStockLayer::where('warehouse_id', $transfer->from_warehouse_id)
            ->where('product_id', $productTransfer->product_id)
            ->where('remaining_qty', '>', 0)
            ->orderBy('id', 'asc')
            ->lockForUpdate();

        if (!empty($productTransfer->variant_id)) {
            $sourceLayersQuery->where('variant_id', $productTransfer->variant_id);
        } else {
            $sourceLayersQuery->whereNull('variant_id');
        }
        $sourceLayersQuery->where('product_batch_id', $productTransfer->product_batch_id ?: null);

        $sourceLayers = $sourceLayersQuery->get();

        foreach ($sourceLayers as $sourceLayer) {
            if ($remainingNeeded <= 0.00001) {
                break;
            }

            $avail = (float)$sourceLayer->remaining_qty;
            $takeQty = min($remainingNeeded, $avail);

            // Decrement source layer
            [$goods, $landed] = $this->consumeLayer($sourceLayer, $takeQty);

            // Lock source batch
            if ($sourceLayer->importBatch && !$sourceLayer->importBatch->is_locked) {
                $sourceLayer->importBatch()->update(['is_locked' => true]);
            }

            // Create destination stock layer with EXACT SAME LANDED COST and BATCH PROVENANCE
            $destLayer = ImportStockLayer::create([
                'import_batch_id' => $sourceLayer->import_batch_id,
                'purchase_id' => $sourceLayer->purchase_id,
                'product_id' => $sourceLayer->product_id,
                'variant_id' => $sourceLayer->variant_id,
                'product_batch_id' => $sourceLayer->product_batch_id,
                'warehouse_id' => $transfer->to_warehouse_id,
                'received_qty' => $takeQty,
                'remaining_qty' => $takeQty,
                'unit_purchase_cost' => $sourceLayer->unit_purchase_cost,
                'unit_landed_cost' => $sourceLayer->unit_landed_cost,
                'total_unit_cost' => $sourceLayer->total_unit_cost,
                'goods_base_amount' => $goods,
                'allocated_landed_amount' => $landed,
                'remaining_goods_amount' => $goods,
                'remaining_landed_amount' => $landed,
                'currency_id' => $sourceLayer->currency_id,
                'source_type' => 'transfer',
                'source_id' => $transfer->id,
            ]);

            $alloc = TransferImportCostAllocation::create([
                'transfer_id' => $transfer->id,
                'product_transfer_id' => $productTransfer->id,
                'source_import_stock_layer_id' => $sourceLayer->id,
                'destination_import_stock_layer_id' => $destLayer->id,
                'transferred_qty' => $takeQty,
                'unit_cost' => $sourceLayer->total_unit_cost,
            ]);

            $allocations[] = $alloc;
            $remainingNeeded = round($remainingNeeded - $takeQty, 4);
        }

        return $allocations;
    }

    /**
     * Validate and reverse transfer allocations when deleting a transfer.
     * BLOCKS reversal if destination stock has been partially or fully consumed.
     */
    public function validateAndReverseTransfer(Transfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
        $allocations = TransferImportCostAllocation::where('transfer_id', $transfer->id)
            ->lockForUpdate()
            ->with(['sourceLayer', 'destinationLayer.importBatch', 'destinationLayer.warehouse'])
            ->get();

        if ($allocations->isEmpty()) {
            return;
        }

        // Integrity Check: Has any destination layer been consumed?
        foreach ($allocations as $alloc) {
            $destLayer = $alloc->destinationLayer;
            if (!$destLayer) {
                throw ValidationException::withMessages(['transfer' => 'Transfer destination provenance is missing.']);
            }

            $destLayer = ImportStockLayer::whereKey($destLayer->id)->lockForUpdate()->firstOrFail();

            if ((float)$destLayer->remaining_qty < (float)$destLayer->received_qty
                || SaleImportCostAllocation::where('import_stock_layer_id', $destLayer->id)->exists()
                || AdjustmentImportCostAllocation::where('import_stock_layer_id', $destLayer->id)->exists()
                || TransferImportCostAllocation::where('source_import_stock_layer_id', $destLayer->id)->exists()) {
                $batchNo = $destLayer->importBatch ? $destLayer->importBatch->batch_number : "ID {$destLayer->import_batch_id}";
                $destWarehouseName = $destLayer->warehouse ? $destLayer->warehouse->name : "Warehouse ID {$destLayer->warehouse_id}";
                $consumedQty = round((float)$destLayer->received_qty - (float)$destLayer->remaining_qty, 4);

                throw ValidationException::withMessages([
                    'transfer' => "Cannot delete or reverse Transfer #{$transfer->reference_no}. {$consumedQty} units of imported stock from batch #{$batchNo} have already been sold, transferred, or consumed in destination warehouse ({$destWarehouseName})."
                ]);
            }
        }

        // If validation passed, reverse layers safely
        foreach ($allocations as $alloc) {
            $sourceLayer = $alloc->sourceLayer;
            $destLayer = $alloc->destinationLayer;

            if (!$sourceLayer) {
                throw ValidationException::withMessages(['transfer' => 'Transfer source provenance is missing.']);
            }
            $sourceLayer = ImportStockLayer::whereKey($sourceLayer->id)->lockForUpdate()->firstOrFail();
            $this->restoreLayerAmounts(
                $sourceLayer, (float) $alloc->transferred_qty,
                (float) $destLayer->remaining_goods_amount, (float) $destLayer->remaining_landed_amount
            );

            if ($destLayer) {
                $alloc->delete();
                $destLayer->delete();
            } else {
                $alloc->delete();
            }
        }
        });
    }

    /**
     * Allocate negative stock adjustment to import layers FIFO.
     * Note: Positive adjustments do NOT invent import provenance.
     */
    public function allocateAdjustmentCost(Adjustment $adjustment, ProductAdjustment $productAdjustment, string $action): array
    {
        // Only negative adjustments reduce layers
        if ($action !== '-') {
            return [];
        }

        $adjustQty = (float)($productAdjustment->qty ?? 0);
        if ($adjustQty <= 0) {
            return [];
        }

        $layersQuery = ImportStockLayer::where('warehouse_id', $adjustment->warehouse_id)
            ->where('product_id', $productAdjustment->product_id)
            ->where('remaining_qty', '>', 0)
            ->orderBy('id', 'asc')
            ->lockForUpdate();

        if (!empty($productAdjustment->variant_id)) {
            $layersQuery->where('variant_id', $productAdjustment->variant_id);
        } else {
            $layersQuery->whereNull('variant_id');
        }

        $layers = $layersQuery->get();
        $allocations = [];
        $remainingNeeded = $adjustQty;

        foreach ($layers as $layer) {
            if ($remainingNeeded <= 0.00001) {
                break;
            }

            $avail = (float)$layer->remaining_qty;
            $takeQty = min($remainingNeeded, $avail);
            [$goods, $landed] = $this->consumeLayer($layer, $takeQty);

            if ($layer->importBatch && !$layer->importBatch->is_locked) {
                $layer->importBatch()->update(['is_locked' => true]);
            }

            $unitCost = (float)$layer->total_unit_cost;
            $totalCost = round($goods + $landed, 4);

            $allocations[] = AdjustmentImportCostAllocation::create([
                'stock_adjustment_id' => $adjustment->id,
                'product_adjustment_id' => $productAdjustment->id,
                'import_stock_layer_id' => $layer->id,
                'adjusted_qty' => $takeQty,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'goods_cost' => $goods,
                'landed_cost' => $landed,
            ]);

            $remainingNeeded = round($remainingNeeded - $takeQty, 4);
        }

        return $allocations;
    }
}
