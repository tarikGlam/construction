<?php

namespace App\Services;

use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockLedgerService
{
    private array $unitCache = [];

    /**
     * Build a transaction-by-transaction stock ledger anchored to the current
     * product_warehouse balance. Running balances are calculated independently
     * for each warehouse/product/variant/batch stock key.
     */
    public function report(array $filters): array
    {
        $start = Carbon::parse($filters['start_date'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $end = Carbon::parse($filters['end_date'] ?? now()->toDateString())->endOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $filters['start_at'] = $start;
        $filters['end_at'] = $end;
        // To derive an as-of opening balance from current stock we also need
        // movements after the selected end date.
        $filters['movement_from'] = $start;

        $movements = collect()
            ->concat($this->purchaseMovements($filters))
            ->concat($this->saleMovements($filters))
            ->concat($this->restaurantModifierMovements($filters))
            ->concat($this->saleReturnMovements($filters))
            ->concat($this->purchaseReturnMovements($filters))
            ->concat($this->transferMovements($filters))
            ->concat($this->adjustmentMovements($filters))
            ->concat($this->materialIssueMovements($filters))
            ->concat($this->materialReturnMovements($filters))
            ->concat($this->damageMovements($filters))
            ->concat($this->productionMovements($filters))
            ->sortBy(fn (array $row) => sprintf('%s-%020d-%s', $row['movement_at'], $row['source_id'], $row['source_type']))
            ->values();

        $unitMap = DB::table('products as p')
            ->leftJoin('units as u', 'p.unit_id', '=', 'u.id')
            ->whereIn('p.id', $movements->pluck('product_id')->unique())
            ->pluck('u.unit_code', 'p.id');
        $movements = $movements->map(function (array $row) use ($unitMap) {
            $row['uom'] = $unitMap[$row['product_id']] ?? null;
            return $row;
        });

        $current = $this->currentStock($filters);
        $afterEnd = $movements->filter(fn (array $row) => Carbon::parse($row['movement_at'])->gt($end))
            ->groupBy('stock_key')->map(fn (Collection $rows) => $rows->sum('net_qty'));
        $period = $movements->filter(function (array $row) use ($start, $end) {
            $at = Carbon::parse($row['movement_at']);
            return $at->betweenIncluded($start, $end);
        })->values();
        $periodNet = $period->groupBy('stock_key')->map(fn (Collection $rows) => $rows->sum('net_qty'));

        $keys = collect($current)->keys()
            ->merge($movements->pluck('stock_key'))
            ->unique()->values();

        $opening = [];
        $closingAsOfEnd = [];
        foreach ($keys as $key) {
            $currentQty = (float) ($current[$key]['qty'] ?? 0);
            $closing = $currentQty - (float) ($afterEnd[$key] ?? 0);
            $closingAsOfEnd[$key] = $closing;
            $opening[$key] = $closing - (float) ($periodNet[$key] ?? 0);
        }

        $running = $opening;
        $period = $period->map(function (array $row) use (&$running) {
            $key = $row['stock_key'];
            $running[$key] = (float) ($running[$key] ?? 0) + (float) $row['net_qty'];
            $row['running_balance'] = $running[$key];
            return $row;
        });

        $summaryRows = [];
        foreach ($keys as $key) {
            $meta = $current[$key] ?? $period->firstWhere('stock_key', $key) ?? $movements->firstWhere('stock_key', $key);
            if (!$meta) continue;
            $summaryRows[] = [
                'stock_key' => $key,
                'warehouse_id' => (int) $meta['warehouse_id'],
                'warehouse_name' => $meta['warehouse_name'] ?? '',
                'product_id' => (int) $meta['product_id'],
                'product_name' => $meta['product_name'] ?? '',
                'product_code' => $meta['product_code'] ?? '',
                'variant_id' => $meta['variant_id'] ?? null,
                'variant_name' => $meta['variant_name'] ?? null,
                'product_batch_id' => $meta['product_batch_id'] ?? null,
                'batch_no' => $meta['batch_no'] ?? null,
                'opening_balance' => (float) ($opening[$key] ?? 0),
                'qty_in' => (float) $period->where('stock_key', $key)->sum('qty_in'),
                'qty_out' => (float) $period->where('stock_key', $key)->sum('qty_out'),
                'closing_balance' => (float) ($closingAsOfEnd[$key] ?? 0),
                'current_stock' => (float) ($current[$key]['qty'] ?? 0),
                'movement_after_period' => (float) ($afterEnd[$key] ?? 0),
            ];
        }

        $period = $this->applySourceFilter($period, $filters)
            ->sortByDesc('movement_at')->values();

        $totalIn = (float) $period->sum('qty_in');
        $totalOut = (float) $period->sum('qty_out');
        $openingQty = (float) collect($summaryRows)->sum('opening_balance');
        $closingQty = (float) collect($summaryRows)->sum('closing_balance');

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'movements' => $period,
            'stock_summary' => collect($summaryRows)->sortBy(fn (array $row) => sprintf('%s|%s|%s', $row['warehouse_name'], $row['product_name'], $row['stock_key']))->values(),
            'summary' => [
                'movement_count' => $period->count(),
                'opening_qty' => $openingQty,
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'closing_qty' => $closingQty,
                'qty_in' => $totalIn,
                'qty_out' => $totalOut,
                'net_qty' => $totalIn - $totalOut,
                'stock_keys' => count($summaryRows),
            ],
        ];
    }

    private function purchaseMovements(array $f): Collection
    {
        $q = DB::table('product_purchases as line')
            ->join('purchases as h', 'line.purchase_id', '=', 'h.id')
            ->join('products as p', 'line.product_id', '=', 'p.id')
            ->join('warehouses as w', 'h.warehouse_id', '=', 'w.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('variants as v', 'line.variant_id', '=', 'v.id')
            ->leftJoin('product_batches as pb', 'line.product_batch_id', '=', 'pb.id')
            ->whereNull('h.deleted_at')->where('line.recieved', '>', 0)
            ->where('h.created_at', '>=', $f['movement_from']);
        $this->applyCommonQueryFilters($q, $f, 'h.warehouse_id', 'line.product_id', 'p', 'line.variant_id', 'line.product_batch_id', 'line.imei_number');
        return $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name',
            'line.product_id','p.name as product_name','p.code as product_code','c.name as category_name','b.title as brand_name',
            'line.variant_id','v.name as variant_name','line.product_batch_id','pb.batch_no','line.imei_number','line.recieved as source_qty','line.purchase_unit_id as unit_id'])
            ->get()->map(fn ($r) => $this->movement($r, 'purchase', $this->toBaseQty($r->source_qty, $r->unit_id), 0));
    }

    private function saleMovements(array $f): Collection
    {
        $q = DB::table('product_sales as line')
            ->join('sales as h', 'line.sale_id', '=', 'h.id')
            ->join('products as p', 'line.product_id', '=', 'p.id')
            ->join('warehouses as w', 'h.warehouse_id', '=', 'w.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('variants as v', 'line.variant_id', '=', 'v.id')
            ->leftJoin('product_batches as pb', 'line.product_batch_id', '=', 'pb.id')
            ->whereNull('h.deleted_at')->whereIn('h.sale_status', [1,5,6])
            ->where('h.created_at', '>=', $f['movement_from']);
        $this->applyWarehouseFilter($q, $f, 'h.warehouse_id');
        $rows = $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name',
            'line.product_id','p.name as product_name','p.code as product_code','p.type','p.product_list','p.qty_list','p.variant_list','p.combo_unit_id','p.category_id','p.brand_id','c.name as category_name','b.title as brand_name',
            'line.variant_id','v.name as variant_name','line.product_batch_id','pb.batch_no','line.imei_number','line.qty as source_qty','line.sale_unit_id as unit_id'])->get();
        return $this->expandSaleLikeRows($rows, $f, 'sale', false);
    }

    private function restaurantModifierMovements(array $f): Collection
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('product_sale_modifiers')) return collect();
        $q = DB::table('product_sale_modifiers as mod')
            ->join('product_sales as line', 'mod.product_sale_id', '=', 'line.id')
            ->join('sales as h', 'line.sale_id', '=', 'h.id')
            ->join('warehouses as w', 'h.warehouse_id', '=', 'w.id')
            ->whereNull('h.deleted_at')->whereIn('h.sale_status', [1,5,6])
            ->where('h.created_at', '>=', $f['movement_from']);
        $this->applyWarehouseFilter($q, $f, 'h.warehouse_id');
        $rows = $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name',
            'line.qty as sale_qty','mod.product_list','mod.qty_list','mod.modifier_group_name','mod.modifier_name'])->get();
        $out = collect();
        foreach ($rows as $row) {
            if (!$row->product_list) continue;
            $ids = explode(',', (string) $row->product_list);
            $qtys = explode(',', (string) $row->qty_list);
            foreach ($ids as $i => $pid) {
                $pid = (int) trim($pid); if (!$pid) continue;
                $product = DB::table('products as p')->leftJoin('categories as c','p.category_id','=','c.id')->leftJoin('brands as b','p.brand_id','=','b.id')
                    ->where('p.id',$pid)->select(['p.id','p.name','p.code','p.category_id','p.brand_id','c.name as category_name','b.title as brand_name'])->first();
                if (!$product || !$this->matchesProductFilters($product, null, $f)) continue;
                $qty = (float) $row->sale_qty * (float) ($qtys[$i] ?? 1);
                $r = (object) ['source_id'=>$row->source_id,'reference_no'=>$row->reference_no,'movement_at'=>$row->movement_at,'warehouse_id'=>$row->warehouse_id,'warehouse_name'=>$row->warehouse_name,
                    'product_id'=>$pid,'product_name'=>$product->name,'product_code'=>$product->code,'category_name'=>$product->category_name,'brand_name'=>$product->brand_name,
                    'variant_id'=>null,'variant_name'=>null,'product_batch_id'=>null,'batch_no'=>null,'imei_number'=>null];
                $out->push($this->movement($r,'sale_modifier_component',0,$qty,trim(($row->modifier_group_name ? $row->modifier_group_name.': ' : '').$row->modifier_name)));
            }
        }
        return $out;
    }

    private function saleReturnMovements(array $f): Collection
    {
        $q = DB::table('product_returns as line')
            ->join('returns as h', 'line.return_id', '=', 'h.id')
            ->join('products as p', 'line.product_id', '=', 'p.id')
            ->join('warehouses as w', 'h.warehouse_id', '=', 'w.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('variants as v', 'line.variant_id', '=', 'v.id')
            ->leftJoin('product_batches as pb', 'line.product_batch_id', '=', 'pb.id')
            ->where('h.created_at', '>=', $f['movement_from']);
        $this->applyWarehouseFilter($q, $f, 'h.warehouse_id');
        $rows = $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name',
            'line.product_id','p.name as product_name','p.code as product_code','p.type','p.product_list','p.qty_list','p.variant_list','p.combo_unit_id','p.category_id','p.brand_id','c.name as category_name','b.title as brand_name',
            'line.variant_id','v.name as variant_name','line.product_batch_id','pb.batch_no','line.imei_number','line.qty as source_qty','line.sale_unit_id as unit_id'])->get();
        return $this->expandSaleLikeRows($rows, $f, 'sale_return', true);
    }

    private function expandSaleLikeRows(Collection $rows, array $filters, string $sourceType, bool $isReturn): Collection
    {
        $result = collect();
        foreach ($rows as $row) {
            if (in_array((string) $row->type, ['service', 'digital'], true)) {
                continue;
            }
            if ((string) $row->type !== 'combo') {
                if (!$this->matchesMovementFilters($row, $filters)) continue;
                $qty = $this->toBaseQty((float) $row->source_qty, (int) $row->unit_id);
                $result->push($this->movement($row, $sourceType, $isReturn ? $qty : 0, $isReturn ? 0 : $qty));
                continue;
            }

            $childIds = explode(',', (string) $row->product_list);
            $childQty = explode(',', (string) $row->qty_list);
            $variantIds = explode(',', (string) ($row->variant_list ?? ''));
            $unitIds = explode(',', (string) ($row->combo_unit_id ?? ''));
            foreach ($childIds as $index => $childId) {
                $childId = (int) trim($childId);
                if (!$childId) continue;
                $child = DB::table('products as p')
                    ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
                    ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
                    ->where('p.id', $childId)
                    ->select(['p.id','p.name','p.code','p.unit_id','p.category_id','p.brand_id','c.name as category_name','b.title as brand_name'])->first();
                if (!$child) continue;
                $variantId = !empty($variantIds[$index]) ? (int) $variantIds[$index] : null;
                if (!$this->matchesProductFilters($child, $variantId, $filters)) continue;
                $required = (float) ($childQty[$index] ?? 1);
                $comboUnitId = !empty($unitIds[$index]) ? (int) $unitIds[$index] : 0;
                if ($comboUnitId && $comboUnitId !== (int) $child->unit_id) {
                    $required = $this->toBaseQty($required, $comboUnitId);
                }
                $qty = (float) $row->source_qty * $required;
                $childRow = (object) [
                    'source_id' => $row->source_id, 'reference_no' => $row->reference_no, 'movement_at' => $row->movement_at,
                    'warehouse_id' => $row->warehouse_id, 'warehouse_name' => $row->warehouse_name,
                    'product_id' => $child->id, 'product_name' => $child->name, 'product_code' => $child->code,
                    'category_name' => $child->category_name, 'brand_name' => $child->brand_name,
                    'variant_id' => $variantId,
                    'variant_name' => $variantId ? DB::table('variants')->where('id', $variantId)->value('name') : null,
                    'product_batch_id' => null, 'batch_no' => null, 'imei_number' => null,
                ];
                $note = 'Combo component of '.$row->product_name.' ['.$row->product_code.']';
                $result->push($this->movement($childRow, $sourceType.'_combo_component', $isReturn ? $qty : 0, $isReturn ? 0 : $qty, $note));
            }
        }
        return $result;
    }

    private function purchaseReturnMovements(array $f): Collection
    {
        $q = DB::table('purchase_product_return as line')
            ->join('return_purchases as h', 'line.return_id', '=', 'h.id')
            ->join('products as p', 'line.product_id', '=', 'p.id')
            ->join('warehouses as w', 'h.warehouse_id', '=', 'w.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('variants as v', 'line.variant_id', '=', 'v.id')
            ->leftJoin('product_batches as pb', 'line.product_batch_id', '=', 'pb.id')
            ->where('h.created_at', '>=', $f['movement_from']);
        $this->applyCommonQueryFilters($q, $f, 'h.warehouse_id', 'line.product_id', 'p', 'line.variant_id', 'line.product_batch_id', 'line.imei_number');
        return $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name',
            'line.product_id','p.name as product_name','p.code as product_code','c.name as category_name','b.title as brand_name',
            'line.variant_id','v.name as variant_name','line.product_batch_id','pb.batch_no','line.imei_number','line.qty as source_qty','line.purchase_unit_id as unit_id'])
            ->get()->map(fn ($r) => $this->movement($r, 'purchase_return', 0, $this->toBaseQty($r->source_qty, $r->unit_id)));
    }

    private function transferMovements(array $f): Collection
    {
        $q = DB::table('product_transfer as line')
            ->join('transfers as h', 'line.transfer_id', '=', 'h.id')
            ->join('products as p', 'line.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('variants as v', 'line.variant_id', '=', 'v.id')
            ->leftJoin('product_batches as pb', 'line.product_batch_id', '=', 'pb.id')
            ->whereIn('h.status', [1,3])->where('h.created_at', '>=', $f['movement_from']);
        if (!empty($f['product_id'])) $q->where('line.product_id', $f['product_id']);
        if (!empty($f['category_id'])) $q->where('p.category_id', $f['category_id']);
        if (!empty($f['brand_id'])) $q->where('p.brand_id', $f['brand_id']);
        if (!empty($f['variant_id'])) $q->where('line.variant_id', $f['variant_id']);
        if (!empty($f['product_batch_id'])) $q->where('line.product_batch_id', $f['product_batch_id']);
        if (!empty($f['imei'])) $q->where('line.imei_number', 'like', '%'.$f['imei'].'%');
        if (!empty($f['allowed_warehouse_id'])) {
            $wid = (int) $f['allowed_warehouse_id'];
            $q->where(function ($w) use ($wid) { $w->where('h.from_warehouse_id', $wid)->orWhere('h.to_warehouse_id', $wid); });
        } elseif (!empty($f['warehouse_id'])) {
            $wid = (int) $f['warehouse_id'];
            $q->where(function ($w) use ($wid) { $w->where('h.from_warehouse_id', $wid)->orWhere('h.to_warehouse_id', $wid); });
        }
        $rows = $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.status','h.from_warehouse_id','h.to_warehouse_id',
            'line.product_id','p.name as product_name','p.code as product_code','c.name as category_name','b.title as brand_name',
            'line.variant_id','v.name as variant_name','line.product_batch_id','pb.batch_no','line.imei_number','line.qty as source_qty','line.purchase_unit_id as unit_id'])->get();
        $warehouseNames = DB::table('warehouses')->whereIn('id', $rows->pluck('from_warehouse_id')->merge($rows->pluck('to_warehouse_id'))->unique())->pluck('name','id');
        $result = collect();
        foreach ($rows as $r) {
            $qty = $this->toBaseQty($r->source_qty, $r->unit_id);
            $requestedWarehouse = (int) ($f['allowed_warehouse_id'] ?? $f['warehouse_id'] ?? 0);
            if (!$requestedWarehouse || $requestedWarehouse === (int) $r->from_warehouse_id) {
                $clone = clone $r; $clone->warehouse_id = $r->from_warehouse_id; $clone->warehouse_name = $warehouseNames[$r->from_warehouse_id] ?? '';
                $result->push($this->movement($clone, 'transfer_out', 0, $qty, 'To: '.($warehouseNames[$r->to_warehouse_id] ?? $r->to_warehouse_id)));
            }
            if ((int) $r->status === 1 && (!$requestedWarehouse || $requestedWarehouse === (int) $r->to_warehouse_id)) {
                $clone = clone $r; $clone->warehouse_id = $r->to_warehouse_id; $clone->warehouse_name = $warehouseNames[$r->to_warehouse_id] ?? '';
                $result->push($this->movement($clone, 'transfer_in', $qty, 0, 'From: '.($warehouseNames[$r->from_warehouse_id] ?? $r->from_warehouse_id)));
            }
        }
        return $result;
    }

    private function materialIssueMovements(array $f): Collection
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('material_issues')) return collect();
        $q = DB::table('material_issue_items as line')->join('material_issues as h','line.material_issue_id','=','h.id')
            ->join('products as p','line.product_id','=','p.id')->join('warehouses as w','h.warehouse_id','=','w.id')
            ->leftJoin('categories as c','p.category_id','=','c.id')->leftJoin('brands as b','p.brand_id','=','b.id')
            ->leftJoin('variants as v','line.variant_id','=','v.id')->leftJoin('product_batches as pb','line.product_batch_id','=','pb.id')
            ->where('h.created_at','>=',$f['movement_from']);
        $this->applyCommonQueryFilters($q,$f,'h.warehouse_id','line.product_id','p','line.variant_id','line.product_batch_id',null);
        return $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name','line.product_id','p.name as product_name','p.code as product_code','c.name as category_name','b.title as brand_name','line.variant_id','v.name as variant_name','line.product_batch_id','pb.batch_no','line.quantity as source_qty','h.project_id'])
            ->get()->map(fn($r)=>$this->movement($r,'material_issue',0,(float)$r->source_qty,'Project #'.$r->project_id));
    }

    private function materialReturnMovements(array $f): Collection
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('material_returns')) return collect();
        $q = DB::table('material_return_items as line')->join('material_returns as h','line.material_return_id','=','h.id')
            ->join('products as p','line.product_id','=','p.id')->join('warehouses as w','h.warehouse_id','=','w.id')
            ->leftJoin('categories as c','p.category_id','=','c.id')->leftJoin('brands as b','p.brand_id','=','b.id')
            ->leftJoin('variants as v','line.variant_id','=','v.id')->leftJoin('product_batches as pb','line.product_batch_id','=','pb.id')
            ->where('h.created_at','>=',$f['movement_from']);
        $this->applyCommonQueryFilters($q,$f,'h.warehouse_id','line.product_id','p','line.variant_id','line.product_batch_id',null);
        return $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name','line.product_id','p.name as product_name','p.code as product_code','c.name as category_name','b.title as brand_name','line.variant_id','v.name as variant_name','line.product_batch_id','pb.batch_no','line.quantity as source_qty','h.project_id'])
            ->get()->map(fn($r)=>$this->movement($r,'material_return',(float)$r->source_qty,0,'Project #'.$r->project_id));
    }

    private function adjustmentMovements(array $f): Collection
    {
        $q = DB::table('product_adjustments as line')->join('adjustments as h', 'line.adjustment_id', '=', 'h.id')
            ->join('products as p', 'line.product_id', '=', 'p.id')->join('warehouses as w','h.warehouse_id','=','w.id')
            ->leftJoin('categories as c','p.category_id','=','c.id')->leftJoin('brands as b','p.brand_id','=','b.id')
            ->leftJoin('variants as v','line.variant_id','=','v.id')->where('h.created_at','>=',$f['movement_from']);
        $this->applyCommonQueryFilters($q,$f,'h.warehouse_id','line.product_id','p','line.variant_id',null,null);
        return $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name','line.product_id',
            'p.name as product_name','p.code as product_code','c.name as category_name','b.title as brand_name','line.variant_id','v.name as variant_name','line.qty as source_qty','line.action'])
            ->get()->map(fn($r) => $this->movement($r,'adjustment',$r->action === '+' ? (float)$r->source_qty : 0,$r->action === '-' ? (float)$r->source_qty : 0,$r->action === '+' ? 'Stock increase' : 'Stock decrease'));
    }

    private function damageMovements(array $f): Collection
    {
        $q = DB::table('product_damage_stocks as line')->join('damage_stocks as h','line.damage_stock_id','=','h.id')
            ->join('products as p','line.product_id','=','p.id')->join('warehouses as w','h.warehouse_id','=','w.id')
            ->leftJoin('categories as c','p.category_id','=','c.id')->leftJoin('brands as b','p.brand_id','=','b.id')
            ->leftJoin('variants as v','line.variant_id','=','v.id')->whereNull('h.deleted_at')->where('h.created_at','>=',$f['movement_from']);
        $this->applyCommonQueryFilters($q,$f,'h.warehouse_id','line.product_id','p','line.variant_id',null,null);
        return $q->select(['h.id as source_id','h.reference_no','h.created_at as movement_at','h.warehouse_id','w.name as warehouse_name','line.product_id',
            'p.name as product_name','p.code as product_code','c.name as category_name','b.title as brand_name','line.variant_id','v.name as variant_name','line.qty as source_qty'])
            ->get()->map(fn($r) => $this->movement($r,'damage',0,(float)$r->source_qty));
    }

    private function productionMovements(array $f): Collection
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('productions')) return collect();
        $q = DB::table('productions as h')->join('products as finished','h.product_id','=','finished.id')
            ->join('warehouses as w','h.warehouse_id','=','w.id')->where('h.created_at','>=',$f['movement_from']);
        if (!empty($f['allowed_warehouse_id'])) $q->where('h.warehouse_id',$f['allowed_warehouse_id']);
        elseif (!empty($f['warehouse_id'])) $q->where('h.warehouse_id',$f['warehouse_id']);
        $productions = $q->select(['h.*','w.name as warehouse_name','finished.name as finished_name','finished.code as finished_code'])->get();
        $productIds = $productions->pluck('product_id')->merge($productions->flatMap(fn($p) => array_filter(explode(',',(string)$p->product_list))))->map(fn($v)=>(int)$v)->unique();
        $products = DB::table('products as p')->leftJoin('categories as c','p.category_id','=','c.id')->leftJoin('brands as b','p.brand_id','=','b.id')
            ->whereIn('p.id',$productIds)->select(['p.id','p.name','p.code','p.category_id','p.brand_id','c.name as category_name','b.title as brand_name'])->get()->keyBy('id');
        $variants = DB::table('variants')->pluck('name','id');
        $out = collect();
        foreach ($productions as $prod) {
            $finished = $products[(int)$prod->product_id] ?? null;
            if ($finished && $this->matchesProductFilters($finished,null,$f)) {
                $r = (object)['source_id'=>$prod->id,'reference_no'=>$prod->reference_no,'movement_at'=>$prod->created_at,'warehouse_id'=>$prod->warehouse_id,'warehouse_name'=>$prod->warehouse_name,
                    'product_id'=>$prod->product_id,'product_name'=>$finished->name,'product_code'=>$finished->code,'category_name'=>$finished->category_name,'brand_name'=>$finished->brand_name,
                    'variant_id'=>null,'variant_name'=>null,'product_batch_id'=>null,'batch_no'=>null,'imei_number'=>null];
                $out->push($this->movement($r,'production_output',(float)$prod->total_qty,0));
            }
            $ids = explode(',',(string)$prod->product_list); $qtys = explode(',',(string)$prod->qty_list); $units = explode(',',(string)$prod->production_units_ids);
            $waste = explode(',',(string)($prod->wastage_percent ?? '')); $variantIds = explode(',',(string)($prod->variant_list ?? ''));
            foreach ($ids as $i=>$pid) {
                $pid=(int)$pid; if (!$pid || empty($products[$pid])) continue; $variantId = !empty($variantIds[$i]) ? (int)$variantIds[$i] : null;
                $p=$products[$pid]; if (!$this->matchesProductFilters($p,$variantId,$f)) continue;
                $qty=$this->toBaseQty((float)($qtys[$i]??0),(int)($units[$i]??0)); $qty *= 1 + ((float)($waste[$i]??0)/100);
                $r=(object)['source_id'=>$prod->id,'reference_no'=>$prod->reference_no,'movement_at'=>$prod->created_at,'warehouse_id'=>$prod->warehouse_id,'warehouse_name'=>$prod->warehouse_name,
                    'product_id'=>$pid,'product_name'=>$p->name,'product_code'=>$p->code,'category_name'=>$p->category_name,'brand_name'=>$p->brand_name,
                    'variant_id'=>$variantId,'variant_name'=>$variantId ? ($variants[$variantId]??null) : null,'product_batch_id'=>null,'batch_no'=>null,'imei_number'=>null];
                $out->push($this->movement($r,'production_component',0,$qty,'Includes wastage'));
            }
        }
        return $out;
    }

    private function currentStock(array $f): array
    {
        $q=DB::table('product_warehouse as pw')->join('products as p','pw.product_id','=','p.id')->join('warehouses as w','pw.warehouse_id','=','w.id')
            ->leftJoin('categories as c','p.category_id','=','c.id')->leftJoin('brands as b','p.brand_id','=','b.id')->leftJoin('variants as v','pw.variant_id','=','v.id')->leftJoin('product_batches as pb','pw.product_batch_id','=','pb.id');
        $this->applyCommonQueryFilters($q,$f,'pw.warehouse_id','pw.product_id','p','pw.variant_id','pw.product_batch_id','pw.imei_number');
        $rows=$q->select(['pw.warehouse_id','w.name as warehouse_name','pw.product_id','p.name as product_name','p.code as product_code','pw.variant_id','v.name as variant_name','pw.product_batch_id','pb.batch_no','pw.qty'])->get();
        $result=[];
        foreach($rows as $r){$key=$this->stockKey($r->warehouse_id,$r->product_id,$r->variant_id,$r->product_batch_id); if(!isset($result[$key])){$result[$key]=['warehouse_id'=>(int)$r->warehouse_id,'warehouse_name'=>$r->warehouse_name,'product_id'=>(int)$r->product_id,'product_name'=>$r->product_name,'product_code'=>$r->product_code,'variant_id'=>$r->variant_id ? (int)$r->variant_id:null,'variant_name'=>$r->variant_name,'product_batch_id'=>$r->product_batch_id ? (int)$r->product_batch_id:null,'batch_no'=>$r->batch_no,'qty'=>0.0];}$result[$key]['qty']+=(float)$r->qty;}
        return $result;
    }

    private function movement(object $r,string $type,float $in,float $out,?string $note=null): array
    {
        $variantId=$r->variant_id ?? null; $batchId=$r->product_batch_id ?? null;
        return ['movement_at'=>(string)$r->movement_at,'source_type'=>$type,'source_id'=>(int)$r->source_id,'reference_no'=>(string)($r->reference_no??''),'warehouse_id'=>(int)$r->warehouse_id,'warehouse_name'=>(string)($r->warehouse_name??''),
            'product_id'=>(int)$r->product_id,'product_name'=>(string)($r->product_name??''),'product_code'=>(string)($r->product_code??''),'category_name'=>$r->category_name??null,'brand_name'=>$r->brand_name??null,
            'variant_id'=>$variantId ? (int)$variantId:null,'variant_name'=>$r->variant_name??null,'product_batch_id'=>$batchId ? (int)$batchId:null,'batch_no'=>$r->batch_no??null,'imei_number'=>$r->imei_number??null,
            'qty_in'=>$in,'qty_out'=>$out,'net_qty'=>$in-$out,'running_balance'=>0.0,'note'=>$note,'stock_key'=>$this->stockKey($r->warehouse_id,$r->product_id,$variantId,$batchId)];
    }

    private function applyWarehouseFilter($q, array $f, string $warehouse): void
    {
        if (!empty($f['allowed_warehouse_id'])) $q->where($warehouse, $f['allowed_warehouse_id']);
        elseif (!empty($f['warehouse_id'])) $q->where($warehouse, $f['warehouse_id']);
    }

    private function matchesMovementFilters(object $row, array $f): bool
    {
        if (!empty($f['product_id']) && (int) $row->product_id !== (int) $f['product_id']) return false;
        if (!empty($f['category_id']) && (int) ($row->category_id ?? 0) !== (int) $f['category_id']) return false;
        if (!empty($f['brand_id']) && (int) ($row->brand_id ?? 0) !== (int) $f['brand_id']) return false;
        if (!empty($f['variant_id']) && (int) ($row->variant_id ?? 0) !== (int) $f['variant_id']) return false;
        if (!empty($f['product_batch_id']) && (int) ($row->product_batch_id ?? 0) !== (int) $f['product_batch_id']) return false;
        if (!empty($f['imei']) && stripos((string) ($row->imei_number ?? ''), (string) $f['imei']) === false) return false;
        return true;
    }

    private function applyCommonQueryFilters($q,array $f,string $warehouse,string $product,string $productAlias,?string $variant,?string $batch,?string $imei): void
    {
        if (!empty($f['allowed_warehouse_id'])) $q->where($warehouse,$f['allowed_warehouse_id']); elseif (!empty($f['warehouse_id'])) $q->where($warehouse,$f['warehouse_id']);
        if (!empty($f['product_id'])) $q->where($product,$f['product_id']); if (!empty($f['category_id'])) $q->where($productAlias.'.category_id',$f['category_id']); if (!empty($f['brand_id'])) $q->where($productAlias.'.brand_id',$f['brand_id']);
        if ($variant && !empty($f['variant_id'])) $q->where($variant,$f['variant_id']); if ($batch && !empty($f['product_batch_id'])) $q->where($batch,$f['product_batch_id']); if ($imei && !empty($f['imei'])) $q->where($imei,'like','%'.$f['imei'].'%');
    }

    private function matchesProductFilters(object $p,?int $variantId,array $f): bool
    {
        if (!empty($f['product_id']) && (int)$p->id !== (int)$f['product_id']) return false; if (!empty($f['category_id']) && (int)$p->category_id !== (int)$f['category_id']) return false; if (!empty($f['brand_id']) && (int)$p->brand_id !== (int)$f['brand_id']) return false; if (!empty($f['variant_id']) && (int)$variantId !== (int)$f['variant_id']) return false; if (!empty($f['product_batch_id']) || !empty($f['imei'])) return false; return true;
    }

    private function applySourceFilter(Collection $rows,array $f): Collection
    {
        if (empty($f['source_type'])) return $rows;
        $source = (string) $f['source_type'];
        if ($source === 'sale') return $rows->filter(fn ($r) => in_array($r['source_type'], ['sale','sale_combo_component','sale_modifier_component'], true));
        if ($source === 'sale_return') return $rows->filter(fn ($r) => in_array($r['source_type'], ['sale_return','sale_return_combo_component'], true));
        return $rows->where('source_type',$source);
    }

    private function toBaseQty(float $qty,?int $unitId): float
    {
        if (!$unitId) return $qty; if (!array_key_exists($unitId,$this->unitCache)) $this->unitCache[$unitId]=Unit::find($unitId); $unit=$this->unitCache[$unitId]; if(!$unit) return $qty; $factor=(float)$unit->operation_value; if($factor<=0) return $qty; return $unit->operator==='/' ? $qty/$factor : $qty*$factor;
    }

    private function stockKey($warehouseId,$productId,$variantId,$batchId): string
    { return implode(':',[(int)$warehouseId,(int)$productId,(int)($variantId?:0),(int)($batchId?:0)]); }
}
