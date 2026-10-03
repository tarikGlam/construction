<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportStockLayer extends Model
{
    protected $table = 'import_stock_layers';

    protected $fillable = [
        'import_batch_id',
        'purchase_id',
        'product_id',
        'variant_id',
        'product_batch_id',
        'warehouse_id',
        'received_qty',
        'remaining_qty',
        'unit_purchase_cost',
        'unit_landed_cost',
        'total_unit_cost',
        'goods_base_amount',
        'allocated_landed_amount',
        'remaining_goods_amount',
        'remaining_landed_amount',
        'currency_id',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'received_qty' => 'decimal:4',
        'remaining_qty' => 'decimal:4',
        'unit_purchase_cost' => 'decimal:4',
        'unit_landed_cost' => 'decimal:4',
        'total_unit_cost' => 'decimal:4',
        'goods_base_amount' => 'decimal:4',
        'allocated_landed_amount' => 'decimal:4',
        'remaining_goods_amount' => 'decimal:4',
        'remaining_landed_amount' => 'decimal:4',
    ];

    public function importBatch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(Variant::class, 'variant_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function saleAllocations()
    {
        return $this->hasMany(SaleImportCostAllocation::class, 'import_stock_layer_id');
    }

    public function outgoingTransfers()
    {
        return $this->hasMany(TransferImportCostAllocation::class, 'source_import_stock_layer_id');
    }

    public function incomingTransfers()
    {
        return $this->hasMany(TransferImportCostAllocation::class, 'destination_import_stock_layer_id');
    }

    public function adjustments()
    {
        return $this->hasMany(AdjustmentImportCostAllocation::class, 'import_stock_layer_id');
    }
}
