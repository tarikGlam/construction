<?php

namespace App\Services\Domain;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Product_Warehouse;
use App\Models\Unit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Manufacturing\Entities\Production;
use RuntimeException;

class ProductionDomainService
{
    public function create(array $input, int $userId): Production
    {
        return DB::transaction(function () use ($input, $userId) {
            $data = $this->attributes($input);
            $data['reference_no'] = $input['reference_no'] ?? 'production-'.now()->format('Ymd-His-u');
            $data['user_id'] = $userId;
            $production = Production::create($data);
            // Existing SalePro workflow reserves/produces stock at creation, even while pending.
            $this->applyStock($production, $this->movementsFor($production), 1);
            return $production->refresh();
        }, 3);
    }

    public function update(Production $production, array $input): Production
    {
        return DB::transaction(function () use ($production, $input) {
            $locked = Production::query()->lockForUpdate()->findOrFail($production->id);
            if ((int) $locked->status === 1) {
                throw new RuntimeException('Finalized production cannot be edited.');
            }
            if (abs((float) $locked->grand_total - ((float) $locked->total_cost
                + (float) $locked->production_cost + (float) $locked->shipping_cost)) > 0.0001) {
                throw ValidationException::withMessages([
                    'production_cost' => 'Historical production costs do not reconcile. Review this record before editing.',
                ]);
            }
            $newAttributes = $this->attributes(array_merge($input, [
                'created_at' => $input['created_at'] ?? $locked->created_at,
            ]));
            $stockFields = ['warehouse_id', 'product_id', 'total_qty', 'product_list', 'qty_list',
                'production_units_ids', 'wastage_percent', 'variant_list'];
            $stockChanged = false;
            foreach ($stockFields as $field) {
                if ((string) $locked->{$field} !== (string) $newAttributes[$field]) {
                    $stockChanged = true;
                    break;
                }
            }
            if ($stockChanged) {
                $this->applyStock($locked, $this->movementsFor($locked), -1);
            }
            $locked->update($newAttributes);
            if ($stockChanged) {
                $this->applyStock($locked, $this->movementsFor($locked), 1);
            }
            return $locked->refresh();
        }, 3);
    }

    public function delete(Production $production): void
    {
        DB::transaction(function () use ($production) {
            $locked = Production::query()->lockForUpdate()->findOrFail($production->id);
            if ((int) $locked->status === 1) {
                throw new RuntimeException('Finalized production cannot be deleted.');
            }
            $this->applyStock($locked, $this->movementsFor($locked), -1);
            $locked->delete();
        }, 3);
    }

    private function attributes(array $input): array
    {
        $products = array_values($input['product_list'] ?? []);
        $quantities = array_values($input['product_qty'] ?? []);
        $unitIds = array_values($input['production_unit_ids'] ?? []);
        $prices = array_values($input['unit_price'] ?? []);
        $wastage = array_values($input['wastage_percent'] ?? []);
        $variants = array_values($input['variant_id'] ?? []);
        if (!$products || count($products) !== count($quantities) || count($products) !== count($unitIds)
            || count($products) !== count($prices)) {
            throw ValidationException::withMessages(['product_list' => 'Each component needs a quantity, unit and unit price.']);
        }
        $status = (int) ($input['status'] ?? 0);
        if (!in_array($status, [0, 1], true) || filter_var($input['total_qty'] ?? 1, FILTER_VALIDATE_INT) === false
            || (int) ($input['total_qty'] ?? 1) <= 0
            || (float) ($input['production_cost'] ?? 0) < 0 || (float) ($input['shipping_cost'] ?? 0) < 0) {
            throw ValidationException::withMessages(['status' => 'Invalid production status, quantity or cost.']);
        }
        $movements = $this->componentMovements($products, $quantities, $unitIds, $wastage, $prices, $variants);
        $componentCost = BigDecimal::zero();
        foreach ($movements as $movement) {
            $componentCost = $componentCost->plus($movement['extended_cost']);
        }
        $extraCost = BigDecimal::of((string) ($input['production_cost'] ?? 0));
        $shipping = BigDecimal::of((string) ($input['shipping_cost'] ?? 0));
        $attributes = [
            'warehouse_id' => (int) $input['warehouse_id'],
            'product_id' => (int) $input['product_id'],
            'item' => count($products),
            'total_qty' => (float) ($input['total_qty'] ?? 1),
            'status' => $status,
            'product_list' => implode(',', $products),
            'qty_list' => implode(',', $quantities),
            'price_list' => implode(',', $prices),
            'wastage_percent' => implode(',', array_map(fn ($i) => $wastage[$i] ?? 0, array_keys($products))),
            'production_units_ids' => implode(',', $unitIds),
            'variant_list' => implode(',', array_map(fn ($i) => $variants[$i] ?? '', array_keys($products))),
            'total_tax' => 0,
            'total_cost' => (float) (string) $componentCost,
            'production_cost' => (float) (string) $extraCost,
            'shipping_cost' => (float) (string) $shipping,
            'grand_total' => (float) (string) $componentCost->plus($extraCost)->plus($shipping),
            'note' => $input['note'] ?? null,
            'created_at' => isset($input['created_at']) ? date('Y-m-d H:i:s', strtotime($input['created_at'])) : now(),
        ];
        if (array_key_exists('document', $input)) {
            $attributes['document'] = $input['document'];
        }
        return $attributes;
    }

    private function movementsFor(Production $production): array
    {
        return $this->componentMovements(
            explode(',', $production->product_list),
            explode(',', $production->qty_list),
            explode(',', $production->production_units_ids),
            explode(',', $production->wastage_percent ?? ''),
            explode(',', $production->price_list ?? ''),
            explode(',', $production->variant_list ?? '')
        );
    }

    public function componentMovements(array $products, array $quantities, array $unitIds, array $wastage, array $prices, array $variants = []): array
    {
        $result = [];
        foreach ($products as $index => $productId) {
            $unit = Unit::findOrFail($unitIds[$index]);
            $factor = (float) $unit->operation_value;
            $quantity = (float) ($quantities[$index] ?? 0);
            $wastePercent = (float) ($wastage[$index] ?? 0);
            $price = (float) ($prices[$index] ?? -1);
            if ($quantity <= 0 || $factor <= 0 || $wastePercent < 0 || $price < 0 || !in_array($unit->operator, ['*', '/'], true)) {
                throw ValidationException::withMessages(['product_qty' => 'Invalid component quantity, conversion, wastage or price.']);
            }
            $converted = $unit->operator === '/' ? $quantity / $factor : $quantity * $factor;
            $consumed = $converted * (1 + $wastePercent / 100);
            $result[] = [
                'product_id' => (int) $productId,
                'variant_id' => !empty($variants[$index]) ? (int) $variants[$index] : null,
                'bom_qty' => $quantity,
                'unit_id' => (int) $unit->id,
                'operator' => $unit->operator,
                'operation_value' => $factor,
                'converted_qty' => $converted,
                'wastage_percent' => $wastePercent,
                'wastage_qty' => $consumed - $converted,
                'consumed_qty' => $consumed,
                'cost_basis' => $price,
                'extended_cost' => (string) BigDecimal::of((string) $consumed)->multipliedBy((string) $price),
            ];
        }
        return $result;
    }

    private function applyStock(Production $production, array $movements, int $direction): void
    {
        $warehouseId = (int) $production->warehouse_id;
        $this->move((int) $production->product_id, null, $warehouseId, $direction * (float) $production->total_qty);
        foreach ($movements as $movement) {
            $this->move($movement['product_id'], $movement['variant_id'], $warehouseId, -$direction * $movement['consumed_qty']);
        }
    }

    private function move(int $productId, ?int $variantId, int $warehouseId, float $delta): void
    {
        $product = Product::query()->lockForUpdate()->findOrFail($productId);
        if ($delta < 0 && (float) $product->qty + $delta < -0.000001) {
            throw ValidationException::withMessages(['product_qty' => 'Insufficient component or finished-product stock.']);
        }
        $product->increment('qty', $delta);
        if ($variantId) {
            $variant = ProductVariant::where('product_id', $productId)->where('variant_id', $variantId)
                ->lockForUpdate()->firstOrFail();
            if ($delta < 0 && (float) $variant->qty + $delta < -0.000001) {
                throw ValidationException::withMessages(['product_qty' => 'Insufficient variant stock.']);
            }
            $variant->increment('qty', $delta);
        }
        $stock = Product_Warehouse::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)->where('variant_id', $variantId)
            ->lockForUpdate()->first();
        if ($stock) {
            if ($delta < 0 && (float) $stock->qty + $delta < -0.000001) {
                throw ValidationException::withMessages(['product_qty' => 'Insufficient warehouse stock.']);
            }
            $stock->increment('qty', $delta);
        } else {
            if ($delta < 0) {
                throw ValidationException::withMessages(['product_qty' => 'Component stock is unavailable in this warehouse.']);
            }
            Product_Warehouse::create([
                'product_id' => $productId, 'warehouse_id' => $warehouseId,
                'variant_id' => $variantId, 'qty' => $delta,
            ]);
        }
    }
}
