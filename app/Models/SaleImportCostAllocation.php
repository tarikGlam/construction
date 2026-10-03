<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleImportCostAllocation extends Model
{
    protected $table = 'sale_import_cost_allocations';

    protected $fillable = [
        'sale_id',
        'product_sale_id',
        'import_batch_id',
        'import_stock_layer_id',
        'warehouse_id',
        'product_id',
        'variant_id',
        'allocated_qty',
        'unit_landed_cost',
        'total_cost',
        'goods_cost',
        'landed_cost',
        'selling_price',
        'revenue',
        'realized_gross_profit',
        'currency_id',
    ];

    protected $casts = [
        'allocated_qty' => 'decimal:4',
        'unit_landed_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'goods_cost' => 'decimal:4',
        'landed_cost' => 'decimal:4',
        'selling_price' => 'decimal:4',
        'revenue' => 'decimal:4',
        'realized_gross_profit' => 'decimal:4',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function productSale()
    {
        return $this->belongsTo(Product_Sale::class, 'product_sale_id');
    }

    public function importBatch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function importStockLayer()
    {
        return $this->belongsTo(ImportStockLayer::class, 'import_stock_layer_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(Variant::class, 'variant_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function returnAllocations()
    {
        return $this->hasMany(ReturnImportCostAllocation::class, 'sale_import_cost_allocation_id');
    }
}
