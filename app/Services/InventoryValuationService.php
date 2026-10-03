<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryValuationService
{
    /**
     * Current company-wide inventory valuation. Historical reconstruction is
     * intentionally unsupported because product_warehouse stores current state.
     *
     * Receipt cost basis includes purchases, production output, and positive
     * stock adjustments. This avoids valuing manufactured/adjusted stock only
     * from stale product.cost fallbacks.
     */
    public function currentValuation(): array
    {
        $receiptHistory = [];

        $purchases = DB::table('product_purchases as pp')
            ->join('purchases as p', 'p.id', '=', 'pp.purchase_id')
            ->whereNull('p.deleted_at')
            ->groupBy('pp.product_id', DB::raw('COALESCE(pp.variant_id, 0)'))
            ->selectRaw('pp.product_id, COALESCE(pp.variant_id, 0) variant_id,
                SUM(CASE WHEN pp.qty > 0 THEN pp.qty ELSE 0 END) receipt_qty,
                SUM(CASE WHEN pp.qty > 0 THEN (pp.net_unit_cost * pp.qty) / COALESCE(NULLIF(p.exchange_rate, 0), 1) ELSE 0 END) receipt_value')
            ->get();
        foreach ($purchases as $row) {
            $this->addReceipt($receiptHistory, (int) $row->product_id, (int) $row->variant_id,
                (float) $row->receipt_qty, (float) $row->receipt_value, 'purchase');
        }

        if (DB::getSchemaBuilder()->hasTable('productions')) {
            $productions = DB::table('productions')
                ->whereNotNull('product_id')
                ->where('total_qty', '>', 0)
                ->selectRaw('CAST(product_id AS UNSIGNED) product_id, SUM(total_qty) receipt_qty, SUM(grand_total) receipt_value')
                ->groupBy(DB::raw('CAST(product_id AS UNSIGNED)'))
                ->get();
            foreach ($productions as $row) {
                $this->addReceipt($receiptHistory, (int) $row->product_id, 0,
                    (float) $row->receipt_qty, (float) $row->receipt_value, 'production');
            }
        }

        if (DB::getSchemaBuilder()->hasTable('product_adjustments')) {
            $adjustments = DB::table('product_adjustments')
                ->where('action', '+')
                ->groupBy('product_id', DB::raw('COALESCE(variant_id, 0)'))
                ->selectRaw('product_id, COALESCE(variant_id, 0) variant_id,
                    SUM(qty) receipt_qty, SUM(qty * COALESCE(unit_cost, 0)) receipt_value')
                ->get();
            foreach ($adjustments as $row) {
                $this->addReceipt($receiptHistory, (int) $row->product_id, (int) $row->variant_id,
                    (float) $row->receipt_qty, (float) $row->receipt_value, 'adjustment_addition');
            }
        }

        $rows = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->select('p.id as product_id', 'p.name', 'p.code', 'p.type', 'p.qty as product_qty',
                'p.cost as fallback_cost', 'pw.warehouse_id', 'w.name as warehouse', 'pw.qty',
                DB::raw('COALESCE(pw.variant_id, 0) variant_id'))
            ->get();

        $items = collect();
        $missingCost = collect();
        $costAnomalies = collect();
        $negativeStock = collect();
        $warehouseTotals = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $key = $row->product_id . ':' . $row->variant_id;
            $history = $receiptHistory[$key] ?? ['qty' => 0.0, 'value' => 0.0, 'sources' => []];
            $receiptQty = (float) $history['qty'];
            $receiptValue = (float) $history['value'];
            $fallback = (float) $row->fallback_cost;
            $explicitZeroCostHistory = false;

            if ($receiptQty > 0.0 && $receiptValue > 0.0) {
                $cost = $receiptValue / $receiptQty;
                $source = count($history['sources']) > 1
                    ? 'weighted_receipt_average:' . implode('+', array_keys($history['sources']))
                    : 'weighted_' . array_key_first($history['sources']) . '_average';
            } elseif ($receiptQty > 0.0 && abs($receiptValue) < 0.0000001) {
                $cost = 0.0;
                $source = 'explicit_zero_cost_receipt_history';
                $explicitZeroCostHistory = true;
            } elseif ($fallback > 0.0) {
                $cost = $fallback;
                $source = $receiptQty > 0.0
                    ? 'product_cost_fallback_zero_receipt_history'
                    : 'product_cost_fallback_no_receipt_history';
                if ($receiptQty > 0.0) {
                    $costAnomalies->push((object) array_merge((array) $row, [
                        'reason' => 'zero_receipt_history_with_positive_product_cost',
                        'receipt_qty' => $receiptQty,
                        'receipt_value' => $receiptValue,
                    ]));
                }
            } else {
                $cost = 0.0;
                $source = 'missing_cost';
            }

            $quantity = (float) $row->qty;
            $value = $quantity * $cost;

            if ($quantity != 0.0 && $cost <= 0.0 && !$explicitZeroCostHistory) {
                $missingCost->push($row);
            }
            if ($quantity < 0.0) $negativeStock->push($row);

            $items->push((object) array_merge((array) $row, [
                'cost' => $cost,
                'source' => $source,
                'value' => $value,
                'receipt_qty' => $receiptQty,
                'receipt_value' => $receiptValue,
                'receipt_sources' => array_keys($history['sources']),
            ]));
            $warehouseTotals[$row->warehouse_id] = ($warehouseTotals[$row->warehouse_id] ?? [
                'warehouse_id' => (int) $row->warehouse_id, 'warehouse' => $row->warehouse, 'value' => 0.0,
            ]);
            $warehouseTotals[$row->warehouse_id]['value'] += $value;
            $total += $value;
        }

        $warehouseQty = $rows->groupBy('product_id')->map(fn ($group) => (float) $group->sum('qty'));
        $quantityMismatches = DB::table('products')->get(['id', 'name', 'code', 'qty'])
            ->filter(fn ($product) => abs((float) $product->qty - (float) ($warehouseQty[$product->id] ?? 0)) > 0.0001)
            ->values();

        return [
            'value' => round($total, 4),
            'items' => $items,
            'warehouses' => collect(array_values($warehouseTotals)),
            'missing_cost' => $missingCost,
            'cost_anomalies' => $costAnomalies,
            'negative_stock' => $negativeStock,
            'quantity_mismatches' => $quantityMismatches,
            'method' => 'weighted_receipt_average_purchase_production_adjustment',
            'cutoff_limitation' => 'current_state_only',
        ];
    }

    private function addReceipt(array &$history, int $productId, int $variantId, float $qty, float $value, string $source): void
    {
        if ($qty <= 0) return;
        $key = $productId . ':' . $variantId;
        $history[$key] ??= ['qty' => 0.0, 'value' => 0.0, 'sources' => []];
        $history[$key]['qty'] += $qty;
        $history[$key]['value'] += $value;
        $history[$key]['sources'][$source] = true;
    }
}
