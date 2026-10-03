<?php

namespace App\Services\Domain;

use App\Models\Adjustment;
use App\Models\Product;
use App\Models\ProductAdjustment;
use App\Models\ProductVariant;
use App\Models\Product_Warehouse;
use App\Models\StockCount;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ComboDefinitionService;
use App\Services\AccountingService;
use App\Services\InvoiceService;
use App\Services\WarehouseAccessService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustmentDomainService
{
    public function __construct(
        protected ?InvoiceService $invoiceService = null,
        protected ?ComboDefinitionService $comboDefinitions = null,
        protected ?WarehouseAccessService $warehouseAccess = null,
        protected ?AccountingService $accounting = null,
    ) {
        $this->invoiceService ??= app(InvoiceService::class);
        $this->comboDefinitions ??= app(ComboDefinitionService::class);
        $this->warehouseAccess ??= app(WarehouseAccessService::class);
        $this->accounting ??= app(AccountingService::class);
    }

    public function createAdjustment(array $data, ?User $user = null): Adjustment
    {
        $warehouseId = $this->validateWarehouse($data['warehouse_id'] ?? null, $user);
        $plan = $this->buildPlan($data, $warehouseId);
        $idempotencyKey = $this->idempotencyKey($data['idempotency_key'] ?? null);
        $fingerprint = hash('sha256', json_encode($plan['fingerprint'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $user, $warehouseId, $plan, $idempotencyKey, $fingerprint) {
            if ($idempotencyKey !== null) {
                $existing = Adjustment::withoutGlobalScope('authorized_warehouse')
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (!hash_equals((string) $existing->idempotency_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'This adjustment replay key was already used with different data.',
                        ]);
                    }

                    return $existing;
                }
            }

            $this->lockAndVerifyPlan($plan);
            $warehouseRows = $this->lockWarehouseRows($plan, $warehouseId);
            $this->assertSubtractionsAvailable($plan['movements'], $warehouseRows);

            $referenceNo = $data['reference_no'] ?? $this->invoiceService->generateInvoiceName('adr-');
            $adjustment = Adjustment::create([
                'reference_no' => $referenceNo,
                'warehouse_id' => $warehouseId,
                'user_id' => $user?->id ?? Auth::id() ?? 1,
                'item' => count($plan['sources']),
                'total_qty' => (string) $plan['total_source_qty'],
                'note' => $data['note'] ?? null,
                'document' => $data['document'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'idempotency_fingerprint' => $idempotencyKey === null ? null : $fingerprint,
                'composition_snapshot' => $plan['sources'],
                'created_at' => isset($data['created_at'])
                    ? normalize_to_sql_datetime($data['created_at'])
                    : now(),
            ]);

            foreach ($plan['movements'] as $movement) {
                $key = $this->identityKey($movement['product_id'], $movement['variant_id']);
                $warehouseRow = $warehouseRows->get($key);
                if (!$warehouseRow) {
                    $warehouseRow = Product_Warehouse::create([
                        'product_id' => $movement['product_id'],
                        'warehouse_id' => $warehouseId,
                        'variant_id' => $movement['variant_id'],
                        'qty' => 0,
                    ]);
                    $warehouseRows->put($key, $warehouseRow);
                }

                $delta = BigDecimal::of($movement['quantity']);
                if ($movement['action'] === '-') {
                    $delta = $delta->negated();
                }

                $product = $plan['locked_products']->get($movement['product_id']);
                $product->qty = (string) BigDecimal::of((string) $product->qty)->plus($delta);
                $product->save();

                $warehouseRow->qty = (string) BigDecimal::of((string) $warehouseRow->qty)->plus($delta);
                $warehouseRow->save();

                if ($movement['product_variant_id'] !== null) {
                    $variant = $plan['locked_variants']->get($movement['product_variant_id']);
                    $variant->qty = (string) BigDecimal::of((string) $variant->qty)->plus($delta);
                    $variant->save();
                }

                $createdProdAdj = ProductAdjustment::create([
                    'product_id' => $movement['product_id'],
                    'variant_id' => $movement['variant_id'],
                    'adjustment_id' => $adjustment->id,
                    'qty' => $movement['quantity'],
                    'unit_cost' => $movement['unit_cost'],
                    'action' => $movement['action'],
                ]);

                if ($movement['action'] === '-') {
                    app(\App\Services\ImportCostAllocationService::class)->allocateAdjustmentCost(
                        $adjustment,
                        $createdProdAdj,
                        '-'
                    );
                }
            }

            if (!empty($data['stock_count_id'])) {
                StockCount::whereKey((int) $data['stock_count_id'])->update(['is_adjusted' => true]);
            }

            $accounting = $this->accounting->recordInventoryAdjustment($adjustment);
            if (!$accounting->isSuccess()) {
                throw new \RuntimeException($accounting->getMessage() ?: 'Inventory adjustment accounting failed.');
            }

            return $adjustment;
        }, 3);
    }

    /** Reverse the persisted physical snapshot; current combo definitions are never consulted. */
    public function reverseAndDelete(int $adjustmentId): ?string
    {
        return DB::transaction(function () use ($adjustmentId) {
            $adjustment = Adjustment::withoutGlobalScope('authorized_warehouse')
                ->whereKey($adjustmentId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->warehouseAccess->authorizeWarehouse((int) $adjustment->warehouse_id);

            $reversal = $this->accounting->reverseTransaction(Adjustment::class, (int) $adjustment->id, '_deleted');
            if (!$reversal->isSuccess()) {
                throw new \RuntimeException($reversal->getMessage() ?: 'Inventory adjustment accounting reversal failed.');
            }

            $lines = ProductAdjustment::where('adjustment_id', $adjustment->id)
                ->orderBy('product_id')
                ->orderByRaw('COALESCE(variant_id, 0)')
                ->lockForUpdate()
                ->get();
            $productIds = $lines->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $variants = ProductVariant::whereIn('product_id', $productIds)
                ->whereIn('variant_id', $lines->whereNotNull('variant_id')->pluck('variant_id'))
                ->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn (ProductVariant $variant) => $this->identityKey($variant->product_id, $variant->variant_id));
            $warehouseRows = Product_Warehouse::where('warehouse_id', $adjustment->warehouse_id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')->orderByRaw('COALESCE(variant_id, 0)')->orderBy('id')
                ->lockForUpdate()->get()
                ->groupBy(fn (Product_Warehouse $row) => $this->identityKey((int) $row->product_id, $row->variant_id));

            foreach ($lines as $line) {
                $key = $this->identityKey((int) $line->product_id, $line->variant_id);
                $product = $products->get((int) $line->product_id);
                $matches = $warehouseRows->get($key, collect());
                if ($matches->count() > 1) {
                    throw ValidationException::withMessages(['adjustment' => 'The original adjustment stock identity is ambiguous.']);
                }
                $warehouseRow = $matches->first();
                if (!$product || !$warehouseRow) {
                    throw ValidationException::withMessages(['adjustment' => 'The original adjustment stock row no longer exists; reversal was cancelled.']);
                }

                $delta = BigDecimal::of((string) $line->qty);
                if ($line->action === '+') {
                    $available = BigDecimal::of((string) $warehouseRow->qty);
                    if ($available->isLessThan($delta)) {
                        throw ValidationException::withMessages([
                            'adjustment' => "Cannot reverse adjustment: component '{$product->name}' has {$available} available but {$delta} is required.",
                        ]);
                    }
                    $delta = $delta->negated();
                }

                $product->qty = (string) BigDecimal::of((string) $product->qty)->plus($delta);
                $warehouseRow->qty = (string) BigDecimal::of((string) $warehouseRow->qty)->plus($delta);
                $product->save();
                $warehouseRow->save();

                if ($line->variant_id !== null) {
                    $variant = $variants->get($key);
                    if (!$variant) {
                        throw ValidationException::withMessages(['adjustment' => 'The original adjustment variant no longer exists; reversal was cancelled.']);
                    }
                    $variant->qty = (string) BigDecimal::of((string) $variant->qty)->plus($delta);
                    $variant->save();
                }
            }

            $document = $adjustment->document;

            // Restore any import layers reduced by this adjustment
            $adjAllocs = \App\Models\AdjustmentImportCostAllocation::where('stock_adjustment_id', $adjustment->id)->get();
            foreach ($adjAllocs as $alloc) {
                $layer = \App\Models\ImportStockLayer::find($alloc->import_stock_layer_id);
                if (!$layer) {
                    throw ValidationException::withMessages(['adjustment' => 'Imported stock provenance is missing.']);
                }
                app(\App\Services\ImportCostAllocationService::class)->restoreLayerAmounts(
                    $layer, (float) $alloc->adjusted_qty,
                    (float) $alloc->goods_cost, (float) $alloc->landed_cost
                );
                $alloc->delete();
            }

            ProductAdjustment::where('adjustment_id', $adjustment->id)->delete();
            $adjustment->delete();

            return $document;
        }, 3);
    }

    private function buildPlan(array $data, int $warehouseId): array
    {
        $productIds = array_values((array) ($data['product_id'] ?? []));
        $codes = array_values((array) ($data['product_code'] ?? []));
        $quantities = array_values((array) ($data['qty'] ?? []));
        $actions = array_values((array) ($data['action'] ?? []));

        if ($productIds === [] || count($productIds) !== count($quantities) || count($productIds) !== count($actions)) {
            throw ValidationException::withMessages(['product_id' => 'Adjustment product, quantity, and action arrays must have matching non-zero lengths.']);
        }
        if ($codes !== [] && count($codes) !== count($productIds)) {
            throw ValidationException::withMessages(['product_code' => 'Adjustment product code array does not match the product array.']);
        }

        $sourceProducts = Product::whereIn('id', array_map('intval', $productIds))->get()->keyBy('id');
        $movements = [];
        $sources = [];
        $signatures = [];
        $variantSignatures = [];
        $totalSourceQty = BigDecimal::zero();

        foreach ($productIds as $index => $rawProductId) {
            $productId = filter_var($rawProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $product = $productId ? $sourceProducts->get((int) $productId) : null;
            if (!$product) {
                throw ValidationException::withMessages(['product_id' => 'Product at adjustment line '.($index + 1).' was not found.']);
            }
            if ((int) $product->is_active !== 1) {
                throw ValidationException::withMessages(['product_id' => "Product '{$product->name}' is inactive or archived."]);
            }
            if (!in_array($product->type, ['standard', 'combo'], true)) {
                throw ValidationException::withMessages(['product_id' => "Product '{$product->name}' is not adjustable physical inventory."]);
            }

            $quantity = $this->positiveDecimal($quantities[$index] ?? null, "Quantity for '{$product->name}'");
            $action = (string) ($actions[$index] ?? '');
            if (!in_array($action, ['+', '-'], true)) {
                throw ValidationException::withMessages(['action' => "Action for '{$product->name}' must be addition or subtraction."]);
            }
            $submittedCode = isset($codes[$index]) ? trim((string) $codes[$index]) : (string) $product->code;
            $totalSourceQty = $totalSourceQty->plus($quantity);
            $signatures[(int) $product->id] = $this->productSignature($product);

            $source = [
                'line' => $index,
                'product_id' => (int) $product->id,
                'product_code' => (string) $product->code,
                'product_name' => (string) $product->name,
                'type' => (string) $product->type,
                'quantity' => (string) $quantity,
                'action' => $action,
                'components' => [],
            ];

            if ($product->type === 'combo') {
                if ($submittedCode !== (string) $product->code) {
                    throw ValidationException::withMessages(['product_code' => "Submitted code does not match combo '{$product->name}'."]);
                }
                foreach ($this->comboDefinitions->resolveForAdjustment($product) as $component) {
                    $componentProduct = Product::findOrFail($component['product_id']);
                    $signatures[(int) $componentProduct->id] = $this->productSignature($componentProduct);
                    $required = BigDecimal::of($component['required_base_quantity'])->multipliedBy($quantity);
                    if ($component['product_variant_id'] !== null) {
                        $productVariant = ProductVariant::findOrFail($component['product_variant_id']);
                        $variantSignatures[(int) $productVariant->id] = $this->variantSignature($productVariant);
                    }
                    $this->aggregateMovement($movements, [
                        'product_id' => (int) $component['product_id'],
                        'variant_id' => $component['variant_id'],
                        'product_variant_id' => $component['product_variant_id'],
                        'quantity' => (string) $required,
                        'action' => $action,
                        'unit_cost' => (string) $componentProduct->cost,
                        'name' => (string) $componentProduct->name,
                        'item_code' => (string) $component['item_code'],
                    ]);
                    $source['components'][] = [
                        'position' => $component['position'],
                        'product_id' => $component['product_id'],
                        'variant_id' => $component['variant_id'],
                        'product_variant_id' => $component['product_variant_id'],
                        'item_code' => $component['item_code'],
                        'required_per_combo' => $component['required_base_quantity'],
                        'movement_quantity' => (string) $required,
                        'unit_id' => $component['unit_id'],
                        'action' => $action,
                    ];
                }
            } else {
                if ($product->is_batch || $product->is_imei) {
                    throw ValidationException::withMessages([
                        'product_id' => "Batch or IMEI identifiers are required for '{$product->name}', but stock adjustments do not provide them.",
                    ]);
                }

                $productVariant = null;
                $variantId = null;
                if ($product->is_variant) {
                    $productVariant = ProductVariant::where('product_id', $product->id)->where('item_code', $submittedCode)->first();
                    if (!$productVariant) {
                        throw ValidationException::withMessages(['product_code' => "Variant code '{$submittedCode}' does not belong to '{$product->name}'."]);
                    }
                    $variantId = (int) $productVariant->variant_id;
                    $variantSignatures[(int) $productVariant->id] = $this->variantSignature($productVariant);
                } elseif ($submittedCode !== (string) $product->code) {
                    throw ValidationException::withMessages(['product_code' => "Submitted code does not match product '{$product->name}'."]);
                }

                $this->aggregateMovement($movements, [
                    'product_id' => (int) $product->id,
                    'variant_id' => $variantId,
                    'product_variant_id' => $productVariant?->id,
                    'quantity' => (string) $quantity,
                    'action' => $action,
                    'unit_cost' => (string) ((float) $product->cost + (float) ($productVariant?->additional_cost ?? 0)),
                    'name' => (string) $product->name,
                    'item_code' => $submittedCode,
                ]);
            }

            $sources[] = $source;
        }

        ksort($signatures, SORT_NUMERIC);
        ksort($variantSignatures, SORT_NUMERIC);
        ksort($movements, SORT_STRING);

        return [
            'warehouse_id' => $warehouseId,
            'sources' => $sources,
            'movements' => array_values($movements),
            'product_signatures' => $signatures,
            'variant_signatures' => $variantSignatures,
            'total_source_qty' => $totalSourceQty,
            'fingerprint' => ['warehouse_id' => $warehouseId, 'sources' => $sources],
        ];
    }

    private function lockAndVerifyPlan(array &$plan): void
    {
        $productIds = array_keys($plan['product_signatures']);
        sort($productIds, SORT_NUMERIC);
        $lockedProducts = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($plan['product_signatures'] as $id => $signature) {
            $product = $lockedProducts->get((int) $id);
            if (!$product || $this->productSignature($product) !== $signature) {
                throw ValidationException::withMessages(['product_id' => 'A product or combo composition changed while the adjustment was being prepared. Please retry.']);
            }
        }

        $variantIds = array_keys($plan['variant_signatures']);
        sort($variantIds, SORT_NUMERIC);
        $lockedVariants = $variantIds === []
            ? collect()
            : ProductVariant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($plan['variant_signatures'] as $id => $signature) {
            $variant = $lockedVariants->get((int) $id);
            if (!$variant || $this->variantSignature($variant) !== $signature) {
                throw ValidationException::withMessages(['product_id' => 'A component variant changed while the adjustment was being prepared. Please retry.']);
            }
        }

        $plan['locked_products'] = $lockedProducts;
        $plan['locked_variants'] = $lockedVariants;
    }

    private function lockWarehouseRows(array $plan, int $warehouseId): Collection
    {
        $productIds = collect($plan['movements'])->pluck('product_id')->unique()->sort()->values();
        $rows = Product_Warehouse::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')->orderByRaw('COALESCE(variant_id, 0)')->orderBy('id')
            ->lockForUpdate()->get();

        $result = collect();
        foreach ($plan['movements'] as $movement) {
            $matches = $rows->filter(function (Product_Warehouse $row) use ($movement) {
                return (int) $row->product_id === $movement['product_id']
                    && ($movement['variant_id'] === null ? $row->variant_id === null : (int) $row->variant_id === $movement['variant_id']);
            });
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['product_id' => "Warehouse stock identity is ambiguous for '{$movement['name']}'."]);
            }
            if ($matches->isNotEmpty()) {
                $result->put($this->identityKey($movement['product_id'], $movement['variant_id']), $matches->first());
            }
        }

        return $result;
    }

    private function assertSubtractionsAvailable(array $movements, Collection $warehouseRows): void
    {
        foreach ($movements as $movement) {
            if ($movement['action'] !== '-') {
                continue;
            }
            $key = $this->identityKey($movement['product_id'], $movement['variant_id']);
            $available = BigDecimal::of((string) ($warehouseRows->get($key)?->qty ?? 0));
            $required = BigDecimal::of($movement['quantity']);
            if ($available->isLessThan($required)) {
                $identity = $movement['variant_id'] === null ? '' : " ({$movement['item_code']})";
                throw ValidationException::withMessages([
                    'qty' => "Insufficient stock for component '{$movement['name']}'{$identity}: available {$available}, required {$required}.",
                ]);
            }
        }
    }

    private function aggregateMovement(array &$movements, array $movement): void
    {
        $key = $movement['action'].'|'.$this->identityKey($movement['product_id'], $movement['variant_id']);
        if (isset($movements[$key])) {
            $movements[$key]['quantity'] = (string) BigDecimal::of($movements[$key]['quantity'])->plus($movement['quantity']);
            return;
        }
        $movements[$key] = $movement;
    }

    private function validateWarehouse(mixed $warehouseId, ?User $user): int
    {
        $warehouseId = filter_var($warehouseId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$warehouseId || !Warehouse::withoutGlobalScope('authorized_warehouse')->whereKey($warehouseId)->where('is_active', 1)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => 'An active warehouse is required.']);
        }

        $classification = $this->warehouseAccess->classification($user);
        if (in_array($classification, [WarehouseAccessService::PORTAL_IDENTITY, WarehouseAccessService::INVALID_OPERATIONAL], true)) {
            throw ValidationException::withMessages(['warehouse_id' => 'Warehouse access denied.']);
        }
        if ($this->warehouseAccess->isRestricted($user) && (int) $this->warehouseAccess->warehouseId($user) !== (int) $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => 'Warehouse access denied.']);
        }

        return (int) $warehouseId;
    }

    private function positiveDecimal(mixed $value, string $label): BigDecimal
    {
        $value = trim((string) $value);
        if (!preg_match('/^(?:\d+\.?\d*|\.\d+)$/', $value)) {
            throw ValidationException::withMessages(['qty' => "{$label} must be a positive number."]);
        }
        $decimal = BigDecimal::of($value);
        if ($decimal->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['qty' => "{$label} must be greater than zero."]);
        }

        return $decimal->stripTrailingZeros();
    }

    private function idempotencyKey(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            throw ValidationException::withMessages(['idempotency_key' => 'The adjustment replay key is invalid.']);
        }

        return $value;
    }

    private function productSignature(Product $product): array
    {
        return $product->only([
            'id', 'name', 'code', 'type', 'unit_id', 'cost', 'is_active', 'is_variant', 'is_batch', 'is_imei',
            'product_list', 'variant_list', 'product_variant_list', 'qty_list', 'combo_unit_id',
        ]);
    }

    private function variantSignature(ProductVariant $variant): array
    {
        return $variant->only(['id', 'product_id', 'variant_id', 'item_code', 'additional_cost']);
    }

    private function identityKey(int $productId, mixed $variantId): string
    {
        return $productId.':'.($variantId === null ? '0' : (int) $variantId);
    }
}
