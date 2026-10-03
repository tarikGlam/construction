<?php

namespace App\Services\Domain;

use App\Exceptions\SaleValidationException;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Domain\SaleDomainService;

class TaxCalculationService
{
    /**
     * Calculate line-level tax and subtotal with explicit half-up rounding.
     *
     * @param float $netUnitPrice
     * @param float $qty
     * @param float $taxRate
     * @param int $taxMethod 1 for Exclusive, 2 for Inclusive
     * @param int $decimalPlaces
     * @param float $discount Total line discount amount
     * @return array{net_unit_price: float, tax: float, total: float}
     */
    public function calculateLineTax(
        float $netUnitPrice,
        float $qty,
        float $taxRate,
        int $taxMethod = 1,
        int $decimalPlaces = 2,
        float $discount = 0.0
    ): array {
        if ($qty <= 0) {
            return [
                'net_unit_price' => round($netUnitPrice, $decimalPlaces),
                'tax' => 0.0,
                'total' => 0.0,
            ];
        }

        if ($taxMethod === 2) {
            // Inclusive tax
            $grossTotal = round(max(0.0, ($netUnitPrice * $qty) - $discount), $decimalPlaces);
            $subTotalUnit = $qty > 0 ? ($grossTotal / $qty) : 0.0;
            $computedNetUnitPrice = round((100.0 / (100.0 + $taxRate)) * $subTotalUnit, $decimalPlaces);
            $tax = round($grossTotal - ($computedNetUnitPrice * $qty), $decimalPlaces);
            $total = $grossTotal;

            return [
                'net_unit_price' => $computedNetUnitPrice,
                'tax' => max(0.0, $tax),
                'total' => $total,
            ];
        }

        // Exclusive tax
        $taxableSubtotal = round(max(0.0, ($netUnitPrice * $qty) - $discount), $decimalPlaces);
        $tax = round($taxableSubtotal * ($taxRate / 100.0), $decimalPlaces);
        $total = round($taxableSubtotal + $tax, $decimalPlaces);
        $effectiveNetUnitPrice = $qty > 0 ? round($taxableSubtotal / $qty, $decimalPlaces) : round($netUnitPrice, $decimalPlaces);

        return [
            'net_unit_price' => $effectiveNetUnitPrice,
            'tax' => max(0.0, $tax),
            'total' => $total,
        ];
    }

    /**
     * Calculate order-level tax with explicit half-up rounding.
     *
     * @param float $subtotal
     * @param float $orderDiscount
     * @param float $orderTaxRate
     * @param int $decimalPlaces
     * @return float
     */
    public function calculateOrderTax(
        float $subtotal,
        float $orderDiscount,
        float $orderTaxRate,
        int $decimalPlaces = 2
    ): float {
        if ($orderTaxRate <= 0) {
            return 0.0;
        }

        $taxableAmount = max(0.0, $subtotal - $orderDiscount);
        return round($taxableAmount * ($orderTaxRate / 100.0), $decimalPlaces);
    }

    /**
     * Authoritatively derive line-level pricing, discounts, taxes, and order totals.
     *
     * @param array $data
     * @param int $decimalPlaces
     * @param User|null $user
     * @param array|null $resolvedLines
     * @return array
     */
    public function reconcileSaleTotals(array $data, int $decimalPlaces = 2, ?User $user = null, ?array $resolvedLines = null): array
    {
        if (!isset($data['product_id']) || !is_array($data['product_id'])) {
            return $data;
        }

        $user = $user ?? auth()->user();
        $canEditPrice = false;
        if ($user) {
            if ((int) $user->role_id === 1) {
                $canEditPrice = true;
            } else {
                try {
                    if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('price_edit_in_sale')) {
                        $canEditPrice = true;
                    } elseif (method_exists($user, 'can') && $user->can('price_edit_in_sale')) {
                        $canEditPrice = true;
                    }
                } catch (\Throwable $e) {
                    $canEditPrice = false;
                }
            }
        }

        $lineTaxes = [];
        $lineSubtotals = [];
        $lineDiscounts = [];
        $totalQty = 0.0;
        $warehouseId = $data['warehouse_id'] ?? null;

        foreach ($data['product_id'] as $i => $pid) {
            $qty = (float) ($data['qty'][$i] ?? 1);
            $totalQty += $qty;
            $clientDiscount = (float) ($data['discount'][$i] ?? 0);
            $lineDiscounts[] = $clientDiscount;

            $resolvedLine = $resolvedLines[$i] ?? null;
            $product = $resolvedLine['product'] ?? Product::with(['tax', 'unit'])->find($pid);

            if (!$product) {
                throw new SaleValidationException(__('db.product_not_found', ['id' => $pid]) ?: "Product #{$pid} not found in catalog.");
            }
            if (!$product->is_active) {
                throw new SaleValidationException(__('db.product_inactive', ['name' => $product->name]) ?: "Product {$product->name} is inactive.");
            }

            // Authoritative tax rate and method from server records
            if ($product->tax_id && $product->tax && $product->tax->is_active) {
                $taxRate = (float) $product->tax->rate;
            } else {
                $taxRate = 0.0;
            }
            $taxMethod = (int) ($product->tax_method ?? 1);

            // Determine authoritative unit price
            if ($canEditPrice && isset($data['net_unit_price'][$i]) && is_numeric($data['net_unit_price'][$i]) && (float) $data['net_unit_price'][$i] >= 0) {
                $unitPrice = (string) $data['net_unit_price'][$i];

                // POS submits the tax-exclusive unit amount for an inclusive-tax
                // product. calculateLineTax() expects the inclusive (gross) unit
                // price, so rebuild it before authoritative reconciliation. Without
                // this conversion, inclusive tax is stripped a second time (for
                // example AED 4.00 becomes AED 3.81).
                if ($taxMethod === 2 && $qty > 0) {
                    // Prefer the POS gross line subtotal. Reversing tax from a
                    // rounded net unit price loses cents at higher quantities
                    // (AED 2.38 x 6 x 1.05 = AED 14.99 instead of AED 15.00).
                    $submittedSubtotal = $data['subtotal'][$i] ?? null;
                    $grossAfterDiscount = is_numeric($submittedSubtotal) && (float) $submittedSubtotal >= 0
                        ? round((float) $submittedSubtotal, $decimalPlaces)
                        : round(
                            ((float) $unitPrice * $qty) * (1 + ($taxRate / 100.0)),
                            $decimalPlaces
                        );
                    $unitPrice = (string) (($grossAfterDiscount + $clientDiscount) / $qty);
                }
            } else {
                $catalogPrice = (string) ($product->price ?? '0');

                // 1. Warehouse-specific diff price
                if ($product->is_diffPrice && $warehouseId) {
                    $whPrice = Product_Warehouse::where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->value('price');
                    if ($whPrice !== null && (float) $whPrice > 0) {
                        $catalogPrice = (string) $whPrice;
                    }
                }

                // 2. Variant adjustment
                if ($product->is_variant) {
                    if ($resolvedLine !== null) {
                        // Authoritative resolved variant snapshot - no secondary lookup
                        $addPrice = (string) ($resolvedLine['additional_price'] ?? '0.0000');
                        $catalogPrice = bcadd($catalogPrice, $addPrice, 4);
                    } else {
                        // Non-sale callers without pre-resolved lines
                        $variantId = $data['variant_id'][$i] ?? null;
                        $productVariantId = $data['product_variant_id'][$i] ?? null;
                        $itemCode = $data['product_code'][$i] ?? null;

                        $pvQuery = ProductVariant::where('product_id', $product->id);
                        if ($productVariantId) {
                            $pvQuery->where('id', $productVariantId);
                        } elseif ($variantId) {
                            $pvQuery->where('variant_id', $variantId);
                        } elseif ($itemCode && $itemCode !== $product->code) {
                            $pvQuery->where('item_code', $itemCode);
                        } else {
                            throw new SaleValidationException("Variant identification is required for variant product '{$product->name}'.");
                        }
                        $pv = $pvQuery->first();
                        if (!$pv) {
                            throw new SaleValidationException("Variant not found for variant product '{$product->name}'.");
                        }
                        $addPrice = (string) ($pv->additional_price ?? '0.0000');
                        $catalogPrice = bcadd($catalogPrice, $addPrice, 4);
                    }
                }

                // 3. Promotion price
                $today = date('Y-m-d');
                if ($product->promotion && $product->starting_date <= $today && $product->last_date >= $today && $product->promotion_price !== null) {
                    $catalogPrice = (string) $product->promotion_price;
                }

                // 4. Unit conversion for price (standard products)
                if (!in_array($product->type, ['combo', 'digital', 'service'])) {
                    $unitName = $data['sale_unit'][$i] ?? null;
                    $unitId = $data['sale_unit_id'][$i] ?? null;
                    $saleUnit = null;

                    if ($unitName && $unitName !== 'n/a') {
                        $saleUnit = Unit::where('unit_name', $unitName)->orWhere('unit_code', $unitName)->first();
                    } elseif ($unitId) {
                        $saleUnit = Unit::find($unitId);
                    }

                    if ($saleUnit && $saleUnit->id != $product->unit_id) {
                        $opVal = (string) $saleUnit->operation_value;
                        if ($saleUnit->operator == '*') {
                            $catalogPrice = bcmul($catalogPrice, $opVal, 4);
                        } elseif ($saleUnit->operator == '/' && (float) $opVal > 0) {
                            $catalogPrice = bcdiv($catalogPrice, $opVal, 4);
                        }
                    }
                }

                $unitPrice = $catalogPrice;
            }

            // Always write back authoritative values
            $data['tax_rate'][$i] = $taxRate;
            $data['tax_method'][$i] = $taxMethod;

            $result = $this->calculateLineTax((float) $unitPrice, $qty, $taxRate, $taxMethod, $decimalPlaces, $clientDiscount);

            $data['net_unit_price'][$i] = $result['net_unit_price'];
            $data['tax'][$i] = $result['tax'];
            $data['subtotal'][$i] = $result['total'];

            $lineTaxes[] = $result['tax'];
            $lineSubtotals[] = $result['total'];

            $reconciledLines[$i] = [
                'product_id' => $pid,
                'net_unit_price' => $result['net_unit_price'],
                'discount' => $clientDiscount,
                'tax_rate' => $taxRate,
                'tax' => $result['tax'],
                'total' => $result['total'],
            ];
        }

        $data['reconciled_lines'] = $reconciledLines;
        $data['total_qty'] = $totalQty;
        $data['total_discount'] = round(array_sum($lineDiscounts), $decimalPlaces);
        $data['total_tax'] = round(array_sum($lineTaxes), $decimalPlaces);
        $data['total_price'] = round(array_sum($lineSubtotals), $decimalPlaces);

        $orderDiscount = (float) ($data['order_discount'] ?? 0);
        $orderTaxRate = (float) ($data['order_tax_rate'] ?? 0);
        $data['order_tax'] = $this->calculateOrderTax($data['total_price'], $orderDiscount, $orderTaxRate, $decimalPlaces);

        $shippingCost = (float) ($data['shipping_cost'] ?? 0);
        $couponDiscount = (float) ($data['coupon_discount'] ?? 0);

        $data['grand_total'] = round(
            ($data['total_price'] + $data['order_tax'] + $shippingCost) - $orderDiscount - $couponDiscount,
            $decimalPlaces
        );

        return $data;
    }
}
