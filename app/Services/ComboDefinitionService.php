<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Variant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ComboDefinitionService
{
    /**
     * Resolve authoritative combo definitions in a fixed number of queries.
     *
     * @return array<int, array{components: array<int, array<string, mixed>>, issues: array<int, string>}>
     */
    public function describeMany(Collection $comboProducts): array
    {
        $comboProducts = $comboProducts->filter(fn (Product $product) => $product->type === 'combo');
        if ($comboProducts->isEmpty()) {
            return [];
        }

        $componentIds = collect();
        $unitIds = collect();
        foreach ($comboProducts as $combo) {
            foreach ($this->csv($combo->product_list) as $value) {
                if (ctype_digit($value) && (int) $value > 0) {
                    $componentIds->push((int) $value);
                }
            }
            foreach ($this->csv($combo->combo_unit_id) as $value) {
                if (ctype_digit($value) && (int) $value > 0) {
                    $unitIds->push((int) $value);
                }
            }
        }

        $componentIds = $componentIds->unique()->values();
        $products = Product::query()
            ->whereIn('id', $componentIds)
            ->get(['id', 'name', 'code', 'type', 'unit_id', 'is_active', 'is_variant', 'is_batch', 'is_imei'])
            ->keyBy('id');

        $productVariants = ProductVariant::query()
            ->whereIn('product_id', $componentIds)
            ->get(['id', 'product_id', 'variant_id', 'item_code'])
            ->groupBy('product_id');
        $variantIds = $productVariants->flatten(1)->pluck('variant_id')->unique()->values();
        $variants = Variant::query()->whereIn('id', $variantIds)->pluck('name', 'id');

        $unitIds->push(...$products->pluck('unit_id')->filter()->all());
        $units = Unit::query()
            ->whereIn('id', $unitIds->unique()->values())
            ->get(['id', 'unit_name', 'base_unit', 'operator', 'operation_value'])
            ->keyBy('id');

        $result = [];
        foreach ($comboProducts as $combo) {
            $result[(int) $combo->id] = $this->describeOne($combo, $products, $productVariants, $variants, $units);
        }

        return $result;
    }

    /**
     * Resolve valid physical movements and reject an unsafe definition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveForAdjustment(Product $combo): array
    {
        $description = $this->describeMany(collect([$combo]))[(int) $combo->id] ?? ['components' => [], 'issues' => []];
        if ($description['issues'] !== []) {
            throw ValidationException::withMessages(['product_id' => $description['issues'][0]]);
        }

        foreach ($description['components'] as $component) {
            if ($component['status'] !== 'valid') {
                throw ValidationException::withMessages([
                    'product_id' => $component['message'] ?: "Combo '{$combo->name}' has an invalid component.",
                ]);
            }
        }

        return $description['components'];
    }

    private function describeOne(
        Product $combo,
        Collection $products,
        Collection $productVariants,
        Collection $variants,
        Collection $units
    ): array {
        $productIds = $this->csv($combo->product_list);
        $variantIds = $this->csv($combo->variant_list);
        $productVariantIds = $this->csv($combo->product_variant_list);
        $quantities = $this->csv($combo->qty_list);
        $comboUnitIds = $this->csv($combo->combo_unit_id);

        if ($productIds === []) {
            return [
                'components' => [],
                'issues' => ["Combo '{$combo->name}' has no component products defined."],
            ];
        }

        $components = [];
        $issues = [];
        foreach ($productIds as $index => $rawProductId) {
            $component = [
                'position' => $index,
                'product_id' => null,
                'product_name' => null,
                'variant_id' => null,
                'product_variant_id' => null,
                'variant_name' => null,
                'item_code' => null,
                'required_quantity' => null,
                'required_base_quantity' => null,
                'unit_id' => null,
                'unit_name' => null,
                'status' => 'malformed',
                'message' => null,
                'is_batch' => false,
                'is_imei' => false,
            ];

            if (!ctype_digit($rawProductId) || (int) $rawProductId <= 0) {
                $component['message'] = "Combo '{$combo->name}' contains a malformed component product ID at position ".($index + 1).'.';
                $issues[] = $component['message'];
                $components[] = $component;
                continue;
            }

            $productId = (int) $rawProductId;
            $component['product_id'] = $productId;
            $product = $products->get($productId);
            if (!$product) {
                $component['status'] = 'missing';
                $component['message'] = "Component product ID {$productId} for combo '{$combo->name}' was not found.";
                $issues[] = $component['message'];
                $components[] = $component;
                continue;
            }

            $component['product_name'] = $product->name;
            $component['item_code'] = $product->code;
            $component['is_batch'] = (bool) $product->is_batch;
            $component['is_imei'] = (bool) $product->is_imei;

            $error = null;
            if ((int) $product->is_active !== 1) {
                $component['status'] = 'inactive';
                $error = "Component product '{$product->name}' for combo '{$combo->name}' is inactive or archived.";
            } elseif ($product->type === 'combo') {
                $error = "Nested or cyclic combo component '{$product->name}' is not supported.";
            } elseif (in_array($product->type, ['service', 'digital'], true)) {
                $error = "Component product '{$product->name}' is not physical inventory.";
            } elseif ($product->is_batch) {
                $error = "Batch identifier is required for component '{$product->name}'; combo adjustments cannot fabricate one.";
            } elseif ($product->is_imei) {
                $error = "IMEI or serial identifiers are required for component '{$product->name}'; combo adjustments cannot fabricate them.";
            }

            $rawVariantId = $variantIds[$index] ?? '';
            $rawProductVariantId = $productVariantIds[$index] ?? '';
            $hasVariantId = $this->hasIdentity($rawVariantId);
            $hasProductVariantId = $this->hasIdentity($rawProductVariantId);
            $variantsForProduct = $productVariants->get($productId, collect());

            if ($product->is_variant) {
                if (!$hasVariantId && !$hasProductVariantId) {
                    $error ??= "Variant identification is required for component '{$product->name}'.";
                } else {
                    $byVariant = $hasVariantId
                        ? $variantsForProduct->where('variant_id', (int) $rawVariantId)
                        : collect();
                    $byProductVariant = $hasProductVariantId
                        ? $variantsForProduct->where('id', (int) $rawProductVariantId)
                        : collect();

                    if (($hasVariantId && $byVariant->count() !== 1) || ($hasProductVariantId && $byProductVariant->count() !== 1)) {
                        $error ??= "Variant identity for component '{$product->name}' is missing or ambiguous.";
                    } else {
                        $resolved = $hasProductVariantId ? $byProductVariant->first() : $byVariant->first();
                        if ($hasVariantId && $hasProductVariantId && (int) $byVariant->first()->id !== (int) $resolved->id) {
                            $error ??= "Variant identifiers conflict for component '{$product->name}'.";
                        } else {
                            $component['variant_id'] = (int) $resolved->variant_id;
                            $component['product_variant_id'] = (int) $resolved->id;
                            $component['variant_name'] = $variants->get($resolved->variant_id);
                            $component['item_code'] = $resolved->item_code;
                        }
                    }
                }
            } elseif ($hasVariantId || $hasProductVariantId) {
                $error ??= "Non-variant component '{$product->name}' cannot have a variant identifier.";
            }

            $rawQuantity = $quantities[$index] ?? '';
            if (!$this->isPositiveDecimal($rawQuantity)) {
                $error ??= "Component quantity for '{$product->name}' is missing or invalid.";
            } else {
                $quantity = BigDecimal::of($rawQuantity)->stripTrailingZeros();
                $component['required_quantity'] = (string) $quantity;
            }

            $rawUnitId = $comboUnitIds[$index] ?? '';
            $unitId = $this->hasIdentity($rawUnitId) ? (int) $rawUnitId : (int) $product->unit_id;
            $unit = $units->get($unitId);
            if (!$unit) {
                $error ??= "Unit for component '{$product->name}' was not found.";
            } elseif ($unitId !== (int) $product->unit_id && (int) $unit->base_unit !== (int) $product->unit_id) {
                $error ??= "Unit '{$unit->unit_name}' does not belong to component '{$product->name}'.";
            } else {
                $component['unit_id'] = $unitId;
                $component['unit_name'] = $unit->unit_name;
                if ($component['required_quantity'] !== null) {
                    try {
                        $baseQuantity = BigDecimal::of($component['required_quantity']);
                        if ($unitId !== (int) $product->unit_id) {
                            $operationValue = BigDecimal::of((string) $unit->operation_value);
                            if ($operationValue->isLessThanOrEqualTo(0)) {
                                throw new \InvalidArgumentException('invalid conversion');
                            }
                            if ($unit->operator === '*') {
                                $baseQuantity = $baseQuantity->multipliedBy($operationValue);
                            } elseif ($unit->operator === '/') {
                                $baseQuantity = $baseQuantity->dividedBy($operationValue, 12, RoundingMode::HALF_UP);
                            } else {
                                throw new \InvalidArgumentException('invalid conversion');
                            }
                        }
                        $component['required_base_quantity'] = (string) $baseQuantity->stripTrailingZeros();
                    } catch (\Throwable) {
                        $error ??= "Unit conversion for component '{$product->name}' is invalid.";
                    }
                }
            }

            if ($error !== null) {
                if ($component['status'] === 'malformed') {
                    $component['status'] = 'invalid';
                }
                $component['message'] = $error;
                $issues[] = $error;
            } else {
                $component['status'] = 'valid';
            }

            $components[] = $component;
        }

        return ['components' => $components, 'issues' => $issues];
    }

    /** @return array<int, string> */
    private function csv(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        return array_map('trim', explode(',', (string) $value));
    }

    private function hasIdentity(string $value): bool
    {
        return ctype_digit($value) && (int) $value > 0;
    }

    private function isPositiveDecimal(string $value): bool
    {
        if (!preg_match('/^(?:\d+\.?\d*|\.\d+)$/', $value)) {
            return false;
        }

        try {
            return BigDecimal::of($value)->isGreaterThan(0);
        } catch (\Throwable) {
            return false;
        }
    }
}
