<?php

namespace App\Services\Domain;

use App\Models\{Product, Product_Sale, Product_Warehouse, ProductReturn, ProductVariant, ProductBatch, Returns, Sale, Unit};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReturnImeiService
{
    public function parseImeis(mixed $raw, bool $rejectDuplicates = true): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_string($raw)) {
            $this->invalid('imei_number', 'IMEIs must be submitted as text.');
        }
        $tokens = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $raw)), fn ($v) => $v !== ''));
        if ($rejectDuplicates && count($tokens) !== count(array_unique($tokens, SORT_STRING))) {
            $this->invalid('imei_number', 'Duplicate IMEI numbers submitted.');
        }
        return $tokens;
    }

    public function formatImeis(array $imeis): ?string
    {
        return $imeis === [] ? null : implode(',', $imeis);
    }

    public function lockBulkReturns(array $returnIds): void
    {
        $saleIds = Returns::whereIn('id', $returnIds)->pluck('sale_id')->unique()->sort();
        Sale::whereIn('id', $saleIds)->orderBy('id')->lockForUpdate()->get();
        $lines = Product_Sale::whereIn('sale_id', $saleIds)->orderBy('id')->lockForUpdate()->get();
        $returns = Returns::whereIn('sale_id', $saleIds)->orderBy('id')->lockForUpdate()->get();
        ProductReturn::whereIn('return_id', $returns->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $ids = $lines->pluck('product_id')->unique()->sort();
        Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        ProductVariant::whereIn('product_id', $ids)->orderBy('id')->lockForUpdate()->get();
        ProductBatch::whereIn('id', $lines->pluck('product_batch_id')->filter()->unique()->sort())->orderBy('id')->lockForUpdate()->get();
        Product_Warehouse::whereIn('product_id', $ids)->whereIn('warehouse_id', $returns->pluck('warehouse_id')->unique())
            ->orderBy('id')->lockForUpdate()->get();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function tuple($row): string
    {
        return implode(':', [(int) $row->product_id, (int) $row->variant_id, (int) $row->product_batch_id]);
    }

    /** Lock sale before return headers in every operation, including deletion. */
    private function context(int $saleId, ?int $returnId = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Return validation requires the mutation transaction.');
        }
        $sale = Sale::whereNull('deleted_at')->whereKey($saleId)->lockForUpdate()->first();
        if (!$sale) {
            $this->invalid('sale_id', 'Original sale is unavailable.');
        }
        $sales = Product_Sale::where('sale_id', $saleId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $headers = Returns::where('sale_id', $saleId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($returnId !== null && !$headers->has($returnId)) {
            $this->invalid('return_id', 'Return has already been deleted or is unavailable.');
        }
        foreach ($headers as $header) {
            if ((int) $header->warehouse_id !== (int) $sale->warehouse_id) {
                $this->invalid('warehouse_id', 'Historical return warehouse differs from the original sale; a separate data audit is required.');
            }
        }
        $history = ProductReturn::whereIn('return_id', $headers->keys())->orderBy('id')->lockForUpdate()->get();
        $ids = $sales->pluck('product_id')->unique()->sort()->values()->all();
        $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variants = ProductVariant::whereIn('product_id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $batches = ProductBatch::whereIn('id', $sales->pluck('product_batch_id')->filter()->unique()->sort())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $stocks = Product_Warehouse::where('warehouse_id', $sale->warehouse_id)->whereIn('product_id', $ids)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Legacy rows have no source ID. Resolve only an unambiguous exact tuple,
        // without persisting a guessed association or repairing historical data.
        $historyBySaleLine = [];
        foreach ($history as $pr) {
            $ps = $pr->product_sale_id ? $sales->get($pr->product_sale_id) : null;
            if (!$pr->product_sale_id) {
                $matches = $sales->filter(fn ($candidate) => $this->tuple($candidate) === $this->tuple($pr));
                if ($matches->count() !== 1) {
                    $this->invalid('product_sale_id', 'Historical return has an ambiguous original sale line; a separate data audit is required.');
                }
                $ps = $matches->first();
            }
            if (!$ps || $this->tuple($ps) !== $this->tuple($pr)) {
                $this->invalid('product_sale_id', 'Historical return identity does not match its original sale line.');
            }
            if ($products->get($ps->product_id)?->is_imei) {
                $serials = $this->parseImeis($pr->imei_number);
                if (!$serials || count($serials) != (float) $pr->qty || array_diff($serials, $this->parseImeis($ps->imei_number))) {
                    $this->invalid('imei_number', 'Historical return IMEIs or quantity are inconsistent; a separate data audit is required.');
                }
            }
            $historyBySaleLine[$ps->id][] = $pr;
        }
        return compact('sale', 'sales', 'headers', 'history', 'products', 'variants', 'batches', 'stocks', 'historyBySaleLine');
    }

    private function row(array $ctx, Product_Sale $ps): array
    {
        $product = $ctx['products']->get($ps->product_id);
        if (!$product) {
            $this->invalid('product_id', 'Original product is unavailable.');
        }
        $variant = null;
        if ($ps->variant_id || $ps->product_variant_id) {
            $matches = $ctx['variants']->filter(fn ($v) =>
                (int) $v->product_id === (int) $ps->product_id
                && (!$ps->variant_id || (int) $v->variant_id === (int) $ps->variant_id)
                && (!$ps->product_variant_id || (int) $v->id === (int) $ps->product_variant_id));
            if ($matches->count() !== 1) {
                $this->invalid('product_sale_id', 'Original variant is missing, foreign, or ambiguous.');
            }
            $variant = $matches->first();
            if ((int) $ps->variant_id !== (int) $variant->variant_id) {
                $this->invalid('product_sale_id', 'Original sale variant identifiers are inconsistent.');
            }
        } elseif ($product->is_variant) {
            $this->invalid('product_sale_id', 'Original sale line is missing its variant identity.');
        }
        $batch = $ps->product_batch_id ? $ctx['batches']->get($ps->product_batch_id) : null;
        if ($ps->product_batch_id && (!$batch || (int) $batch->product_id !== (int) $ps->product_id)) {
            $this->invalid('product_batch_id', 'Original batch does not belong to the product.');
        }
        $matches = $ctx['stocks']->filter(fn ($pw) => $this->tuple($pw) === $this->tuple($ps));
        if ($product->is_imei && $matches->count() !== 1) {
            $this->invalid('warehouse_id', 'An exact, unambiguous original warehouse stock row is required.');
        }
        $unit = $ps->sale_unit_id ? Unit::find($ps->sale_unit_id) : null;
        if ($product->is_imei && $unit) {
            $factor = $unit->operator === '*' ? (float) $unit->operation_value
                : ((float) $unit->operation_value > 0 ? 1 / (float) $unit->operation_value : 0);
            if (abs($factor - 1) > 0.000001) {
                $this->invalid('qty', 'Original IMEI sale unit is not one unit per serial; a separate data audit is required.');
            }
        }
        return [
            'sale' => $ctx['sale'], 'sale_id' => $ctx['sale']->id, 'warehouse_id' => $ctx['sale']->warehouse_id,
            'product_id' => $product->id, 'product' => $product, 'product_sale' => $ps,
            'product_sale_id' => $ps->id, 'variant' => $variant, 'batch' => $batch,
            'variant_id' => $ps->variant_id, 'product_batch_id' => $ps->product_batch_id,
            'warehouse' => $matches->count() === 1 ? $matches->first() : null,
            'sale_unit_id' => $ps->sale_unit_id, 'unit' => $unit,
        ];
    }

    private function prepare(array $data, array $ctx, ?int $returnId = null): array
    {
        $result = [];
        $seen = [];
        $seenImeis = [];
        foreach ($data['product_id'] as $i => $id) {
            $ps = $ctx['sales']->get((int) ($data['product_sale_id'][$i] ?? 0));
            if (!$ps || (int) $ps->product_id !== (int) $id) {
                $this->invalid("product_sale_id.$i", 'Submitted sale line does not belong to this original sale and product.');
            }
            if (isset($seen[$ps->id])) {
                $this->invalid("product_sale_id.$i", 'Duplicate original sale line.');
            }
            $seen[$ps->id] = true;
            $row = $this->row($ctx, $ps);
            foreach (['variant_id' => (int) $row['variant_id'], 'product_variant_id' => (int) ($row['variant']?->id ?? 0), 'product_batch_id' => (int) $row['product_batch_id']] as $field => $expected) {
                if (isset($data[$field][$i]) && $data[$field][$i] !== '' && (int) $data[$field][$i] !== $expected) {
                    $this->invalid("$field.$i", 'Submitted identity differs from the original sale line.');
                }
            }
            $qty = (float) ($data['qty'][$i] ?? 0);
            if (!is_finite($qty) || $qty <= 0) {
                $this->invalid("qty.$i", 'Return quantity must be positive.');
            }
            $old = [];
            $elsewhere = [];
            $otherQty = 0.0;
            foreach ($ctx['historyBySaleLine'][$ps->id] ?? [] as $pr) {
                if ((int) $pr->return_id === $returnId) {
                    $old[] = $pr;
                } else {
                    $elsewhere[] = $pr;
                    $otherQty += (float) $pr->qty;
                }
            }
            if (count($old) > 1) {
                $this->invalid("product_sale_id.$i", 'Historical return has multiple lines for the same original sale line.');
            }
            if ($otherQty + $qty > (float) $ps->qty + 0.000001) {
                $this->invalid("qty.$i", 'Return quantity exceeds remaining original sale quantity.');
            }
            $desired = $this->parseImeis($data['imei_number'][$i] ?? null);
            $oldImeis = [];
            $otherImeis = [];
            if ($row['product']->is_imei) {
                if ($qty !== floor($qty) || !$desired || count($desired) !== (int) $qty) {
                    $this->invalid("imei_number.$i", 'Integer quantity must equal the number of distinct returned IMEIs.');
                }
                $sold = $this->parseImeis($ps->imei_number);
                foreach (array_merge($old, $elsewhere) as $pr) {
                    $tokens = $this->parseImeis($pr->imei_number);
                    if (count($tokens) !== (int) $pr->qty || (float) $pr->qty !== floor((float) $pr->qty) || array_diff($tokens, $sold)) {
                        $this->invalid('imei_number', 'Historical return IMEIs or quantity are inconsistent; a separate data audit is required.');
                    }
                    if ((int) $pr->return_id === $returnId) {
                        $oldImeis = array_merge($oldImeis, $tokens);
                    } else {
                        $otherImeis = array_merge($otherImeis, $tokens);
                    }
                }
                $historyImeis = array_merge($oldImeis, $otherImeis);
                if (count($historyImeis) !== count(array_unique($historyImeis, SORT_STRING))) {
                    $this->invalid('imei_number', 'Historical returns contain duplicate IMEIs.');
                }
                if (array_diff($desired, $sold)) {
                    $this->invalid("imei_number.$i", 'Returned IMEI was not sold on this original sale line.');
                }
                if (array_intersect($desired, $otherImeis)) {
                    $this->invalid("imei_number.$i", 'IMEI has already been returned by another return.');
                }
                if (array_intersect($desired, $seenImeis)) {
                    $this->invalid("imei_number.$i", 'Duplicate IMEI across return lines.');
                }
                $seenImeis = array_merge($seenImeis, $desired);
                $available = $this->parseImeis($row['warehouse']->imei_number);
                if (array_diff($oldImeis, $available)) {
                    $this->invalid('imei_number', 'Previously returned IMEI is no longer available; reversal is unsafe.');
                }
                if (array_intersect(array_diff($desired, $oldImeis), $available)) {
                    $this->invalid("imei_number.$i", 'Returned IMEI is already present in warehouse availability.');
                }
            } elseif ($desired) {
                $this->invalid("imei_number.$i", 'Non-IMEI product cannot receive serial numbers.');
            }
            $result[$i] = $row + [
                'qty' => $qty, 'imeis' => $desired, 'canonical_imei_str' => $this->formatImeis($desired),
                'old_imeis' => $oldImeis, 'old_returns' => $old,
            ];
        }
        return $result;
    }

    public function validateAndPrepareStore(array $data, Sale $sale): array
    {
        return $this->prepare($data, $this->context((int) $sale->id));
    }

    /** Gated fiscal returns must restore the locked original stock tuple, not browser lookups. */
    public function validateFiscalStock(array $rows, array $data): void
    {
        foreach ($rows as $index => $row) {
            $product = $row['product'];
            if ($product->type !== 'standard') {
                $this->invalid('product_id', 'Adjusted fiscal returns currently require standard stock products. This product type is awaiting verification.');
            }
            $unit = $row['unit'];
            if (!$unit || !in_array($unit->operator, ['*', '/'], true)
                || !is_numeric($unit->operation_value) || !is_finite((float) $unit->operation_value) || (float) $unit->operation_value <= 0) {
                $this->invalid('sale_unit', 'The original sale unit has no valid stock conversion.');
            }
            if (($data['sale_unit'][$index] ?? null) !== $unit->unit_name) {
                $this->invalid('sale_unit', 'Return unit must match the original sale unit.');
            }
            if (!$row['warehouse']) {
                $this->invalid('warehouse_id', 'An exact, unambiguous original warehouse stock row is required.');
            }
            $code = $row['variant']?->item_code ?? $product->code;
            if (($data['product_code'][$index] ?? null) !== $code) {
                $this->invalid('product_code', 'Return product code must match the original variant/product.');
            }
        }
    }

    /** Called once per non-serial line after validateFiscalStock, inside the same transaction. */
    public function restoreFiscalStock(array $row): void
    {
        if (DB::transactionLevel() === 0 || $row['product']->is_imei) {
            throw new \LogicException('Fiscal stock requires a transaction and a non-serial source line.');
        }
        $unit = $row['unit'];
        $quantity = $unit->operator === '*' ? $row['qty'] * $unit->operation_value : $row['qty'] / $unit->operation_value;
        if (!is_finite($quantity) || $quantity <= 0) {
            $this->invalid('qty', 'Original stock conversion produces an invalid return quantity.');
        }
        foreach ([$row['product'], $row['variant'], $row['batch'], $row['warehouse']] as $model) {
            if ($model) { $model->increment('qty', $quantity); }
        }
    }

    /** Quantities and serial availability are saved together from the same locked row. */
    private function apply(array $rows): void
    {
        $changes = [];
        foreach ($rows as $row) {
            if (!$row['product']->is_imei) {
                continue;
            }
            $pw = $row['warehouse'];
            $available = $this->parseImeis($pw->imei_number);
            $removed = array_values(array_diff($row['old_imeis'], $row['imeis']));
            $added = array_values(array_diff($row['imeis'], $row['old_imeis']));
            if (array_diff($removed, $available) || array_intersect($added, $available)) {
                $this->invalid('imei_number', 'Warehouse IMEI state does not permit this change.');
            }
            $pw->imei_number = $this->formatImeis(array_values(array_merge(array_diff($available, $removed), $added)));
            $delta = count($added) - count($removed);
            foreach ([$row['product'], $row['variant'], $row['batch'], $pw] as $model) {
                if (!$model) {
                    continue;
                }
                $key = get_class($model).':'.$model->id;
                $changes[$key] ??= ['model' => $model, 'delta' => 0];
                $changes[$key]['delta'] += $delta;
            }
        }
        foreach ($changes as $change) {
            $model = $change['model'];
            if ((float) $model->qty + $change['delta'] < -0.000001) {
                $this->invalid('qty', 'Return reversal would create negative stock.');
            }
            $model->qty += $change['delta'];
            $model->save();
        }
    }

    public function applyStoreImeis(int $warehouseId, array $rows): void
    {
        $this->apply($rows);
    }

    public function validateAndProcessUpdate(Returns $return, array $data): array
    {
        $ctx = $this->context((int) $return->sale_id, (int) $return->id);
        $rows = $this->prepare($data, $ctx, (int) $return->id);
        $desiredIds = array_column($rows, 'product_sale_id');
        $removedRows = [];
        foreach ($ctx['historyBySaleLine'] as $psId => $history) {
            $current = array_values(array_filter($history, fn ($pr) => (int) $pr->return_id === (int) $return->id));
            if (!$current || in_array((int) $psId, $desiredIds, true)) {
                continue;
            }
            $row = $this->row($ctx, $ctx['sales']->get($psId));
            $old = [];
            foreach ($current as $pr) {
                $old = array_merge($old, $this->parseImeis($pr->imei_number));
            }
            $removedRows[] = $row + ['old_imeis' => $old, 'imeis' => [], 'qty' => 0, 'old_returns' => $current];
        }
        $this->apply(array_merge($rows, $removedRows));
        foreach (array_merge($rows, $removedRows) as $row) {
            $ps = $row['product_sale'];
            $oldQty = array_sum(array_map(fn ($pr) => (float) $pr->qty, $row['old_returns']));
            $ps->return_qty = (float) $ps->return_qty + $row['qty'] - $oldQty;
            if ($ps->return_qty < -0.000001) {
                $this->invalid('qty', 'Historical return quantity is inconsistent.');
            }
            $ps->save();
        }
        return $rows;
    }

    public function processDestroy(Returns $return): void
    {
        $ctx = $this->context((int) $return->sale_id, (int) $return->id);
        $rows = [];
        foreach ($ctx['historyBySaleLine'] as $psId => $history) {
            $current = array_values(array_filter($history, fn ($pr) => (int) $pr->return_id === (int) $return->id));
            if (!$current) {
                continue;
            }
            $row = $this->row($ctx, $ctx['sales']->get($psId));
            $old = [];
            $qty = 0;
            foreach ($current as $pr) {
                $tokens = $this->parseImeis($pr->imei_number);
                if ($row['product']->is_imei && (count($tokens) != (float) $pr->qty || array_diff($tokens, $this->parseImeis($row['product_sale']->imei_number)))) {
                    $this->invalid('imei_number', 'Historical return is inconsistent; deletion requires a separate data audit.');
                }
                $old = array_merge($old, $tokens);
                $qty += (float) $pr->qty;
            }
            if (count($old) !== count(array_unique($old, SORT_STRING))) {
                $this->invalid('imei_number', 'Historical return contains duplicate IMEIs.');
            }

            if ($row['product']->is_imei) {
                $survivingImeis = [];
                foreach ($ctx['historyBySaleLine'] as $otherPsId => $otherHistory) {
                    $otherRow = $this->row($ctx, $ctx['sales']->get($otherPsId));
                    if (!$otherRow['product']->is_imei || $otherRow['warehouse']?->id !== $row['warehouse']?->id) {
                        continue;
                    }
                    foreach ($otherHistory as $otherReturn) {
                        if ((int) $otherReturn->return_id !== (int) $return->id) {
                            $survivingImeis = array_merge($survivingImeis, $this->parseImeis($otherReturn->imei_number));
                        }
                    }
                }
                if (array_intersect($old, $survivingImeis)) {
                    $this->invalid(
                        'imei_number',
                        'A surviving historical return contains the same IMEI; deletion requires a separate data audit.'
                    );
                }
            }

            $rows[] = $row + ['old_imeis' => $old, 'imeis' => []];
            if ($row['product']->is_imei) {
                $ps = $row['product_sale'];
                if ((float) $ps->return_qty + 0.000001 < $qty) {
                    $this->invalid('qty', 'Historical returned quantity cannot be reversed safely.');
                }
                $ps->return_qty -= $qty;
                $ps->save();
            }
        }
        $this->apply($rows);
    }
}
