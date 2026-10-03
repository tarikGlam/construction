<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnImportCostAllocation extends Model
{
    protected $table = 'return_import_cost_allocations';

    protected $fillable = [
        'return_id',
        'product_return_id',
        'sale_import_cost_allocation_id',
        'import_stock_layer_id',
        'returned_qty',
        'unit_cost_restored',
        'total_cost_restored',
        'goods_cost_restored',
        'landed_cost_restored',
        'revenue_reversed',
    ];

    protected $casts = [
        'returned_qty' => 'decimal:4',
        'unit_cost_restored' => 'decimal:4',
        'total_cost_restored' => 'decimal:4',
        'goods_cost_restored' => 'decimal:4',
        'landed_cost_restored' => 'decimal:4',
        'revenue_reversed' => 'decimal:4',
    ];

    public function returns()
    {
        return $this->belongsTo(Returns::class, 'return_id');
    }

    public function productReturn()
    {
        return $this->belongsTo(ProductReturn::class, 'product_return_id');
    }

    public function saleImportCostAllocation()
    {
        return $this->belongsTo(SaleImportCostAllocation::class, 'sale_import_cost_allocation_id');
    }

    public function importStockLayer()
    {
        return $this->belongsTo(ImportStockLayer::class, 'import_stock_layer_id');
    }
}
