<?php

namespace Modules\Construction\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Product_Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Construction\Entities\MaterialIssue;
use Modules\Construction\Entities\MaterialIssueItem;
use Modules\Construction\Entities\MaterialReturn;

class InventoryMovementService
{
    public function issue(array $data, array $lines): MaterialIssue
    {
        return DB::transaction(function () use ($data, $lines) {
            $issue = MaterialIssue::create($data);
            foreach ($lines as $line) {
                $stock = Product_Warehouse::query()->lockForUpdate()->findOrFail($line['stock_row_id']);
                $quantity = round((float) $line['quantity'], 4);
                if ((int) $stock->warehouse_id !== (int) $data['warehouse_id'] || $quantity <= 0 || (float) $stock->qty < $quantity) {
                    throw ValidationException::withMessages(['items' => 'Insufficient or invalid Store stock for one of the selected materials.']);
                }
                $product = Product::query()->lockForUpdate()->findOrFail($stock->product_id);
                $unitCost = (float) ($line['unit_cost'] ?? $product->cost ?? 0);
                $stock->decrement('qty', $quantity);
                $product->decrement('qty', $quantity);
                if ($stock->variant_id) {
                    ProductVariant::query()->where('product_id', $product->id)->where('variant_id', $stock->variant_id)->lockForUpdate()->first()?->decrement('qty', $quantity);
                }
                $issue->items()->create([
                    'product_id' => $product->id, 'variant_id' => $stock->variant_id,
                    'product_batch_id' => $stock->product_batch_id, 'quantity' => $quantity,
                    'unit_cost' => $unitCost, 'total_cost' => round($quantity * $unitCost, 4),
                    'cost_category_id' => $line['cost_category_id'] ?? null,
                ]);
            }
            return $issue->load('items');
        });
    }

    public function return(MaterialIssue $issue, array $quantities, array $data): MaterialReturn
    {
        return DB::transaction(function () use ($issue, $quantities, $data) {
            $return = MaterialReturn::create($data + [
                'material_issue_id' => $issue->id, 'project_id' => $issue->project_id,
                'site_id' => $issue->site_id, 'warehouse_id' => $issue->warehouse_id,
            ]);
            $created = 0;
            foreach ($issue->items()->lockForUpdate()->get() as $item) {
                $quantity = round((float) ($quantities[$item->id] ?? 0), 4);
                if ($quantity <= 0) continue;
                $alreadyReturned = DB::table('material_return_items')->join('material_returns', 'material_returns.id', '=', 'material_return_items.material_return_id')
                    ->where('material_returns.material_issue_id', $issue->id)->where('material_return_items.material_issue_item_id', $item->id)->sum('material_return_items.quantity');
                if ($quantity > (float) $item->quantity - (float) $alreadyReturned) {
                    throw ValidationException::withMessages(['items' => 'A return quantity exceeds the outstanding issued quantity.']);
                }
                $stock = Product_Warehouse::query()->where('warehouse_id', $issue->warehouse_id)->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)->where('product_batch_id', $item->product_batch_id)->lockForUpdate()->first();
                if (!$stock) throw ValidationException::withMessages(['items' => 'The original Store stock row no longer exists.']);
                $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                $stock->increment('qty', $quantity); $product->increment('qty', $quantity);
                if ($item->variant_id) ProductVariant::query()->where('product_id', $product->id)->where('variant_id', $item->variant_id)->lockForUpdate()->first()?->increment('qty', $quantity);
                $return->items()->create([
                    'material_issue_item_id' => $item->id, 'product_id' => $item->product_id, 'variant_id' => $item->variant_id,
                    'product_batch_id' => $item->product_batch_id, 'quantity' => $quantity, 'unit_cost' => $item->unit_cost,
                    'total_cost' => round($quantity * (float) $item->unit_cost, 4),
                ]);
                $created++;
            }
            if (!$created) throw ValidationException::withMessages(['items' => 'Enter at least one material return quantity.']);
            return $return;
        });
    }
}
