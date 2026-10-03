<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Product_Warehouse;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

class SaleEditStockGuard
{
    private const EPSILON = 0.000001;

    /**
     * Validate the fully restored stock state before an edited sale is re-applied.
     *
     * SaleController::update() restores the original sale quantities first. This
     * guard then validates the complete replacement payload against that restored
     * state, aggregating duplicate lines/components so the edit cannot create
     * negative warehouse stock when selling without stock is disabled.
     */
    public function assertAvailable(array $data): void
    {
        if (config('without_stock') === 'yes') {
            return;
        }

        $saleStatus = (int) ($data['sale_status'] ?? 1);
        if (!in_array($saleStatus, [1, 5, 6], true)) {
            return;
        }

        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'A valid warehouse is required to validate stock availability.',
            ]);
        }

        $requirements = [];
        $productIds = (array) ($data['product_id'] ?? []);
        $productCodes = (array) ($data['product_code'] ?? []);
        $quantities = (array) ($data['qty'] ?? []);
        $saleUnits = (array) ($data['sale_unit'] ?? []);
        $batchIds = (array) ($data['product_batch_id'] ?? []);

        foreach ($productIds as $index => $rawProductId) {
            $productId = (int) $rawProductId;
            $product = Product::find($productId);
            if (!$product) {
                throw ValidationException::withMessages([
                    "product_id.{$index}" => "Product ID {$productId} was not found.",
                ]);
            }

            $requestedQty = (float) ($quantities[$index] ?? 0);
            if ($requestedQty <= 0) {
                throw ValidationException::withMessages([
                    "qty.{$index}" => "Quantity must be greater than zero for '{$product->name}'.",
                ]);
            }

            $unitName = (string) ($saleUnits[$index] ?? 'n/a');
            $baseQty = $this->convertSaleQuantity($requestedQty, $unitName, $product, $index);

            if ($product->type === 'combo') {
                $this->addComboRequirements($requirements, $product, $baseQty, $warehouseId, $index);
                continue;
            }

            // Services/digital/non-stock sale rows use n/a and are intentionally
            // excluded from physical stock validation, matching existing update logic.
            if ($unitName === 'n/a') {
                continue;
            }

            $variantId = null;
            $batchId = !empty($batchIds[$index]) ? (int) $batchIds[$index] : null;

            if ($product->is_variant) {
                $code = trim((string) ($productCodes[$index] ?? ''));
                $variant = ProductVariant::select('id', 'variant_id')
                    ->FindExactProductWithCode($productId, $code)
                    ->first();

                if (!$variant) {
                    throw ValidationException::withMessages([
                        "product_code.{$index}" => "Variant '{$code}' was not found for '{$product->name}'.",
                    ]);
                }
                $variantId = (int) $variant->variant_id;
                $batchId = null;
            }

            $this->addRequirement(
                $requirements,
                $productId,
                $variantId,
                $batchId,
                $warehouseId,
                $baseQty,
                $product->name,
                $index
            );
        }

        foreach ($requirements as $requirement) {
            $query = Product_Warehouse::where('product_id', $requirement['product_id'])
                ->where('warehouse_id', $requirement['warehouse_id']);

            if ($requirement['variant_id']) {
                $query->where('variant_id', $requirement['variant_id']);
            } elseif ($requirement['batch_id']) {
                $query->where('product_batch_id', $requirement['batch_id']);
            } else {
                // Mirror the legacy stock bucket used by the update path. For
                // non-variant/non-batch rows, prefer the canonical plain row.
                $query->whereNull('variant_id')->whereNull('product_batch_id');
            }

            $warehouseStock = $query->lockForUpdate()->first();
            $available = $warehouseStock ? (float) $warehouseStock->qty : 0.0;
            $required = (float) $requirement['qty'];

            if ($required - $available > self::EPSILON) {
                throw ValidationException::withMessages([
                    "qty.{$requirement['input_index']}" => sprintf(
                        "Insufficient stock for '%s'. Available: %s, requested: %s.",
                        $requirement['label'],
                        $this->formatQty($available),
                        $this->formatQty($required)
                    ),
                ]);
            }

            if ($requirement['batch_id']) {
                $batch = ProductBatch::where('id', $requirement['batch_id'])->lockForUpdate()->first();
                $batchAvailable = $batch ? (float) $batch->qty : 0.0;
                if ($required - $batchAvailable > self::EPSILON) {
                    throw ValidationException::withMessages([
                        "qty.{$requirement['input_index']}" => sprintf(
                            "Insufficient batch stock for '%s'. Available: %s, requested: %s.",
                            $requirement['label'],
                            $this->formatQty($batchAvailable),
                            $this->formatQty($required)
                        ),
                    ]);
                }
            }
        }
    }

    private function convertSaleQuantity(float $qty, string $unitName, Product $product, int $index): float
    {
        if ($unitName === 'n/a' || $unitName === '') {
            return $qty;
        }

        $unit = Unit::where('unit_name', $unitName)->orWhere('unit_code', $unitName)->first();
        if (!$unit) {
            throw ValidationException::withMessages([
                "sale_unit.{$index}" => "Sale unit '{$unitName}' was not found for '{$product->name}'.",
            ]);
        }

        if ($unit->operator === '*') {
            return $qty * (float) $unit->operation_value;
        }

        if ($unit->operator === '/') {
            $factor = (float) $unit->operation_value;
            if (abs($factor) < self::EPSILON) {
                throw ValidationException::withMessages([
                    "sale_unit.{$index}" => "Sale unit '{$unitName}' has an invalid conversion factor.",
                ]);
            }
            return $qty / $factor;
        }

        return $qty;
    }

    private function addComboRequirements(
        array &$requirements,
        Product $combo,
        float $comboBaseQty,
        int $warehouseId,
        int $inputIndex
    ): void {
        $productList = array_map('trim', explode(',', (string) $combo->product_list));
        $variantList = $combo->variant_list ? explode(',', (string) $combo->variant_list) : [];
        $qtyList = $combo->qty_list ? explode(',', (string) $combo->qty_list) : [];
        $unitIds = $combo->combo_unit_id ? explode(',', (string) $combo->combo_unit_id) : [];

        foreach ($productList as $componentIndex => $rawChildId) {
            $childId = (int) $rawChildId;
            if ($childId <= 0) {
                continue;
            }

            $child = Product::find($childId);
            if (!$child) {
                throw ValidationException::withMessages([
                    "product_id.{$inputIndex}" => "Combo '{$combo->name}' references missing product ID {$childId}.",
                ]);
            }

            $componentQty = isset($qtyList[$componentIndex]) ? (float) $qtyList[$componentIndex] : 0.0;
            if ($componentQty <= 0) {
                throw ValidationException::withMessages([
                    "qty.{$inputIndex}" => "Combo '{$combo->name}' has an invalid component quantity for '{$child->name}'.",
                ]);
            }

            if (!empty($unitIds[$componentIndex]) && (int) $unitIds[$componentIndex] !== (int) $child->unit_id) {
                $unit = Unit::find((int) $unitIds[$componentIndex]);
                if ($unit) {
                    if ($unit->operator === '*') {
                        $componentQty *= (float) $unit->operation_value;
                    } elseif ($unit->operator === '/') {
                        $factor = (float) $unit->operation_value;
                        if (abs($factor) < self::EPSILON) {
                            throw ValidationException::withMessages([
                                "qty.{$inputIndex}" => "Combo '{$combo->name}' has an invalid unit conversion for '{$child->name}'.",
                            ]);
                        }
                        $componentQty /= $factor;
                    }
                }
            }

            $variantId = null;
            if (!empty($variantList[$componentIndex])) {
                $variantId = (int) $variantList[$componentIndex];
            }

            $this->addRequirement(
                $requirements,
                $childId,
                $variantId ?: null,
                null,
                $warehouseId,
                $comboBaseQty * $componentQty,
                "{$child->name} (component of {$combo->name})",
                $inputIndex
            );
        }
    }

    private function addRequirement(
        array &$requirements,
        int $productId,
        ?int $variantId,
        ?int $batchId,
        int $warehouseId,
        float $qty,
        string $label,
        int $inputIndex
    ): void {
        $key = implode(':', [
            $warehouseId,
            $productId,
            $variantId ?: 0,
            $batchId ?: 0,
        ]);

        if (!isset($requirements[$key])) {
            $requirements[$key] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'batch_id' => $batchId,
                'warehouse_id' => $warehouseId,
                'qty' => 0.0,
                'label' => $label,
                'input_index' => $inputIndex,
            ];
        }

        $requirements[$key]['qty'] += $qty;
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
    }
}
