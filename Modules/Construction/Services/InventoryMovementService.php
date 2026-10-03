<?php

namespace Modules\Construction\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Product_Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Construction\Entities\MaterialIssue;
use Modules\Construction\Entities\MaterialReturn;

class InventoryMovementService
{
    public function requestIssue(array $data, array $lines): MaterialIssue
    {
        return DB::transaction(function () use ($data, $lines) {
            $data['status'] = 'pending';
            $data['requested_at'] = now();
            $issue = MaterialIssue::create($data);
            foreach ($lines as $line) {
                $stock = Product_Warehouse::query()->with('product')->findOrFail($line['stock_row_id']);
                $quantity = round((float) $line['quantity'], 4);
                if ((int) $stock->warehouse_id !== (int) $data['warehouse_id'] || $quantity <= 0) {
                    throw ValidationException::withMessages(['items' => 'Invalid Store stock selection for one of the requested materials.']);
                }
                $unitCost = (float) ($line['unit_cost'] ?? $stock->product?->cost ?? 0);
                $issue->items()->create([
                    'product_warehouse_id' => $stock->id,
                    'product_id' => $stock->product_id,
                    'variant_id' => $stock->variant_id,
                    'product_batch_id' => $stock->product_batch_id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => round($quantity * $unitCost, 4),
                    'cost_category_id' => $line['cost_category_id'] ?? null,
                ]);
            }
            return $issue->load('items');
        });
    }

    public function approveIssue(MaterialIssue $issue, int $approverId): MaterialIssue
    {
        return DB::transaction(function () use ($issue, $approverId) {
            $issue = MaterialIssue::query()->lockForUpdate()->with('items')->findOrFail($issue->id);
            if ($issue->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Only pending material issues can be approved.']);
            }
            foreach ($issue->items as $item) {
                $stock = Product_Warehouse::query()->lockForUpdate()->findOrFail($item->product_warehouse_id);
                $quantity = round((float) $item->quantity, 4);
                if ((int)$stock->warehouse_id !== (int)$issue->warehouse_id || (float)$stock->qty < $quantity) {
                    throw ValidationException::withMessages(['items' => 'Insufficient Store stock to approve this material issue.']);
                }
                $product = Product::query()->lockForUpdate()->findOrFail($stock->product_id);
                $stock->decrement('qty', $quantity);
                $product->decrement('qty', $quantity);
                if ($stock->variant_id) {
                    ProductVariant::query()->where('product_id',$product->id)->where('variant_id',$stock->variant_id)->lockForUpdate()->first()?->decrement('qty',$quantity);
                }
            }
            $issue->update(['status'=>'issued','approved_by'=>$approverId,'approved_at'=>now(),'rejected_at'=>null,'rejection_reason'=>null]);
            return $issue->fresh('items');
        });
    }

    public function rejectIssue(MaterialIssue $issue, int $approverId, ?string $reason = null): MaterialIssue
    {
        return DB::transaction(function () use ($issue, $approverId, $reason) {
            $issue = MaterialIssue::query()->lockForUpdate()->findOrFail($issue->id);
            if ($issue->status !== 'pending') throw ValidationException::withMessages(['status'=>'Only pending material issues can be rejected.']);
            $issue->update(['status'=>'rejected','approved_by'=>$approverId,'rejected_at'=>now(),'rejection_reason'=>$reason]);
            return $issue->fresh();
        });
    }

    public function issue(array $data, array $lines): MaterialIssue
    {
        $issue = $this->requestIssue($data, $lines);
        return $this->approveIssue($issue, (int)($data['approved_by'] ?? $data['created_by'] ?? 0));
    }

    public function return(MaterialIssue $issue, array $quantities, array $data): MaterialReturn
    {
        if ($issue->status !== 'issued') throw ValidationException::withMessages(['status'=>'Only approved/issued materials can be returned.']);
        return DB::transaction(function () use ($issue, $quantities, $data) {
            $return = MaterialReturn::create($data + [
                'material_issue_id'=>$issue->id,'project_id'=>$issue->project_id,'site_id'=>$issue->site_id,'warehouse_id'=>$issue->warehouse_id,
            ]);
            foreach ($issue->items as $item) {
                $quantity = round((float)($quantities[$item->id] ?? 0),4);
                if ($quantity <= 0) continue;
                $already = (float)DB::table('material_return_items')->where('material_issue_item_id',$item->id)->sum('quantity');
                if ($quantity > ((float)$item->quantity - $already)) throw ValidationException::withMessages(['quantity'=>'Return quantity exceeds remaining issued quantity.']);
                $stock = Product_Warehouse::query()->lockForUpdate()->where('warehouse_id',$issue->warehouse_id)->where('product_id',$item->product_id)
                    ->where(function($q) use($item){$item->variant_id ? $q->where('variant_id',$item->variant_id) : $q->whereNull('variant_id');})->firstOrFail();
                $stock->increment('qty',$quantity);
                Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail()->increment('qty',$quantity);
                if ($item->variant_id) ProductVariant::query()->where('product_id',$item->product_id)->where('variant_id',$item->variant_id)->lockForUpdate()->first()?->increment('qty',$quantity);
                $return->items()->create(['material_issue_item_id'=>$item->id,'product_id'=>$item->product_id,'variant_id'=>$item->variant_id,'product_batch_id'=>$item->product_batch_id,'quantity'=>$quantity,'unit_cost'=>$item->unit_cost,'total_cost'=>round($quantity*(float)$item->unit_cost,4)]);
            }
            return $return->load('items');
        });
    }
}
