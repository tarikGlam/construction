<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdjustmentImportCostAllocation extends Model
{
    protected $table = 'adjustment_import_cost_allocations';

    protected $fillable = [
        'stock_adjustment_id',
        'product_adjustment_id',
        'import_stock_layer_id',
        'adjusted_qty',
        'unit_cost',
        'total_cost',
        'goods_cost',
        'landed_cost',
    ];

    protected $casts = [
        'adjusted_qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'goods_cost' => 'decimal:4',
        'landed_cost' => 'decimal:4',
    ];

    public function stockAdjustment()
    {
        return $this->belongsTo(Adjustment::class, 'stock_adjustment_id');
    }

    public function productAdjustment()
    {
        return $this->belongsTo(ProductAdjustment::class, 'product_adjustment_id');
    }

    public function importStockLayer()
    {
        return $this->belongsTo(ImportStockLayer::class, 'import_stock_layer_id');
    }
}
