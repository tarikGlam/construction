<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\ImportStockLayer;
use App\Models\ReturnImportCostAllocation;
use App\Models\SaleImportCostAllocation;
use App\Models\Warehouse;

class ImportBatchProfitabilityService
{
    public function __construct(
        protected WarehouseAccessService $warehouseAccessService
    ) {}

    /**
     * Compute full container/batch profitability report in base currency
     */
    public function getBatchProfitability(ImportBatch $batch, ?int $userWarehouseId = null, bool $isRestricted = false): array
    {
        $batch->load(['warehouse', 'baseCurrency']);

        // Determine if user view is restricted
        $isWarehouseScoped = $isRestricted && $userWarehouseId !== null;
        $scopedWarehouse = $isWarehouseScoped ? Warehouse::find($userWarehouseId) : null;

        // Base currency symbol & code
        $currencyCode = $batch->baseCurrency ? $batch->baseCurrency->code : 'BASE';
        $currencySymbol = $batch->baseCurrency ? $batch->baseCurrency->symbol : '$';

        // Query layers
        $layersQuery = ImportStockLayer::where('import_batch_id', $batch->id)
            ->with(['warehouse', 'product', 'variant']);

        if ($isWarehouseScoped) {
            $layersQuery->where('warehouse_id', $userWarehouseId);
        }

        $layers = $layersQuery->get();

        // Query sale allocations
        $saleAllocQuery = SaleImportCostAllocation::where('import_batch_id', $batch->id)
            ->with(['warehouse', 'product', 'variant', 'sale']);

        if ($isWarehouseScoped) {
            $saleAllocQuery->where('warehouse_id', $userWarehouseId);
        }

        $saleAllocations = $saleAllocQuery->get();

        // Query return restorations
        $saleAllocIds = $saleAllocations->pluck('id')->toArray();
        $returnAllocations = !empty($saleAllocIds)
            ? ReturnImportCostAllocation::whereIn('sale_import_cost_allocation_id', $saleAllocIds)->get()
            : collect();

        $totalReturnedCost = (float)$returnAllocations->sum('total_cost_restored');
        $totalReturnedQty = (float)$returnAllocations->sum('returned_qty');

        // Totals
        $grossSoldQty = (float)$saleAllocations->sum('allocated_qty');
        $netSoldQty = max(0.0, $grossSoldQty - $totalReturnedQty);

        $grossRevenue = (float)$saleAllocations->sum('revenue');
        $grossCogs = (float)$saleAllocations->sum('total_cost');

        // If returned, adjust cogs and revenue
        $netCogs = max(0.0, $grossCogs - $totalReturnedCost);
        // Estimate revenue returned proportionally
        $returnedRevenue = (float) $returnAllocations->sum('revenue_reversed');
        $netRevenue = max(0.0, $grossRevenue - $returnedRevenue);

        $realizedProfit = round($netRevenue - $netCogs, 4);
        $profitMarginPct = $netRevenue > 0 ? round(($realizedProfit / $netRevenue) * 100, 2) : 0.0;

        $totalReceivedQty = (float)$layers->sum('received_qty');
        $totalRemainingQty = (float)$layers->sum('remaining_qty');
        $unrealizedStockValuation = (float)$layers->sum(function ($l) {
            return (float)$l->remaining_goods_amount + (float)$l->remaining_landed_amount;
        });

        // 1. Warehouse Breakdown
        $warehouseBreakdown = [];
        $allWarehouseIds = $layers->pluck('warehouse_id')->merge($saleAllocations->pluck('warehouse_id'))->unique();

        foreach ($allWarehouseIds as $wId) {
            if ($isWarehouseScoped && (int)$wId !== (int)$userWarehouseId) {
                continue;
            }

            $wLayers = $layers->where('warehouse_id', $wId);
            $wSales = $saleAllocations->where('warehouse_id', $wId);
            $wSaleIds = $wSales->pluck('id')->toArray();
            $wReturns = $returnAllocations->whereIn('sale_import_cost_allocation_id', $wSaleIds);

            $wRecQty = (float)$wLayers->sum('received_qty');
            $wRemQty = (float)$wLayers->sum('remaining_qty');
            $wGrossSold = (float)$wSales->sum('allocated_qty');
            $wRetQty = (float)$wReturns->sum('returned_qty');
            $wNetSold = max(0.0, $wGrossSold - $wRetQty);

            $wGrossRev = (float)$wSales->sum('revenue');
            $wGrossCost = (float)$wSales->sum('total_cost');
            $wRetCost = (float)$wReturns->sum('total_cost_restored');
            $wNetCost = max(0.0, $wGrossCost - $wRetCost);

            $wRetRev = (float) $wReturns->sum('revenue_reversed');
            $wNetRev = max(0.0, $wGrossRev - $wRetRev);

            $wProfit = round($wNetRev - $wNetCost, 4);
            $wMargin = $wNetRev > 0 ? round(($wProfit / $wNetRev) * 100, 2) : 0.0;

            $warehouseModel = Warehouse::find($wId);

            $warehouseBreakdown[] = [
                'warehouse_id' => $wId,
                'warehouse_name' => $warehouseModel ? $warehouseModel->name : "Warehouse #{$wId}",
                'received_qty' => $wRecQty,
                'remaining_qty' => $wRemQty,
                'sold_qty' => $wNetSold,
                'revenue' => round($wNetRev, 2),
                'cogs' => round($wNetCost, 2),
                'realized_profit' => round($wProfit, 2),
                'margin_pct' => $wMargin,
                'stock_value' => round($wLayers->sum(fn($l) => (float)$l->remaining_goods_amount + (float)$l->remaining_landed_amount), 2),
            ];
        }

        // 2. Product Breakdown
        $productBreakdown = [];
        $uniqueProducts = $layers->groupBy(fn($l) => $l->product_id . '_' . ($l->variant_id ?? '0'));

        foreach ($uniqueProducts as $key => $pLayers) {
            $sampleLayer = $pLayers->first();
            $prodId = $sampleLayer->product_id;
            $varId = $sampleLayer->variant_id;

            $pSales = $saleAllocations->where('product_id', $prodId)->where('variant_id', $varId);
            $pSaleIds = $pSales->pluck('id')->toArray();
            $pReturns = $returnAllocations->whereIn('sale_import_cost_allocation_id', $pSaleIds);

            $pRecQty = (float)$pLayers->sum('received_qty');
            $pRemQty = (float)$pLayers->sum('remaining_qty');
            $pGrossSold = (float)$pSales->sum('allocated_qty');
            $pRetQty = (float)$pReturns->sum('returned_qty');
            $pNetSold = max(0.0, $pGrossSold - $pRetQty);

            $pGrossRev = (float)$pSales->sum('revenue');
            $pGrossCost = (float)$pSales->sum('total_cost');
            $pRetCost = (float)$pReturns->sum('total_cost_restored');
            $pNetCost = max(0.0, $pGrossCost - $pRetCost);

            $pRetRev = (float) $pReturns->sum('revenue_reversed');
            $pNetRev = max(0.0, $pGrossRev - $pRetRev);

            $pProfit = round($pNetRev - $pNetCost, 4);
            $pMargin = $pNetRev > 0 ? round(($pProfit / $pNetRev) * 100, 2) : 0.0;

            $productBreakdown[] = [
                'product_id' => $prodId,
                'product_name' => $sampleLayer->product ? $sampleLayer->product->name : "Product #{$prodId}",
                'product_code' => $sampleLayer->product ? $sampleLayer->product->code : '',
                'variant_id' => $varId,
                'variant_name' => $sampleLayer->variant ? $sampleLayer->variant->name : null,
                'unit_purchase_cost' => round((float)$sampleLayer->unit_purchase_cost, 4),
                'unit_landed_cost' => round((float)$sampleLayer->unit_landed_cost, 4),
                'total_unit_cost' => round((float)$sampleLayer->total_unit_cost, 4),
                'received_qty' => $pRecQty,
                'remaining_qty' => $pRemQty,
                'sold_qty' => $pNetSold,
                'revenue' => round($pNetRev, 2),
                'cogs' => round($pNetCost, 2),
                'realized_profit' => round($pProfit, 2),
                'margin_pct' => $pMargin,
                'stock_value' => round($pLayers->sum(fn($l) => (float)$l->remaining_goods_amount + (float)$l->remaining_landed_amount), 2),
            ];
        }

        // Receiving costs belong to the receiving warehouse, even when a later
        // transfer makes a scoped warehouse's own stock and sales visible.
        $redactReceivingCosts = $isWarehouseScoped && (int) $batch->warehouse_id !== (int) $userWarehouseId;
        $reportBatch = $redactReceivingCosts ? clone $batch : $batch;
        if ($redactReceivingCosts) {
            foreach (['total_goods_cost', 'total_landed_cost', 'total_cost'] as $attribute) {
                $reportBatch->setAttribute($attribute, null);
            }
            $reportBatch->unsetRelation('costs');
            $reportBatch->unsetRelation('purchases');
        }

        // Summary metrics
        return [
            'batch' => $reportBatch,
            'is_warehouse_scoped' => $isWarehouseScoped,
            'scoped_warehouse_name' => $scopedWarehouse ? $scopedWarehouse->name : null,
            'currency_code' => $currencyCode,
            'currency_symbol' => $currencySymbol,
            'summary' => [
                'total_goods_cost' => $isWarehouseScoped && (int) $batch->warehouse_id !== (int) $userWarehouseId ? null : round((float)$batch->total_goods_cost, 2),
                'total_landed_cost' => $isWarehouseScoped && (int) $batch->warehouse_id !== (int) $userWarehouseId ? null : round((float)$batch->total_landed_cost, 2),
                'total_combined_cost' => $isWarehouseScoped && (int) $batch->warehouse_id !== (int) $userWarehouseId ? null : round((float)$batch->total_cost, 2),
                'landed_cost_ratio_pct' => $redactReceivingCosts ? null : ((float)$batch->total_goods_cost > 0
                    ? round(((float)$batch->total_landed_cost / (float)$batch->total_goods_cost) * 100, 2)
                    : 0.0),
                'total_received_qty' => $totalReceivedQty,
                'total_sold_qty' => $netSoldQty,
                'total_remaining_qty' => $totalRemainingQty,
                'unrealized_stock_valuation' => round($unrealizedStockValuation, 2),
                'realized_revenue' => round($netRevenue, 2),
                'realized_cogs' => round($netCogs, 2),
                'realized_gross_profit' => round($realizedProfit, 2),
                'gross_margin_pct' => $profitMarginPct,
            ],
            'warehouse_breakdown' => $warehouseBreakdown,
            'product_breakdown' => $productBreakdown,
            'costs' => $isWarehouseScoped && (int) $batch->warehouse_id !== (int) $userWarehouseId ? collect() : $batch->costs,
        ];
    }
}
