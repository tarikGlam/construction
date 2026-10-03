<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnImportCostAllocation extends Model
{
    protected $fillable = [
        'return_purchase_id', 'purchase_product_return_id', 'import_stock_layer_id',
        'returned_qty', 'goods_cost', 'landed_cost',
    ];

    protected $casts = [
        'returned_qty' => 'decimal:4',
        'goods_cost' => 'decimal:4',
        'landed_cost' => 'decimal:4',
    ];
}
