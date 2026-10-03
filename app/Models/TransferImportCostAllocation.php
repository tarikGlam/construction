<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferImportCostAllocation extends Model
{
    protected $table = 'transfer_import_cost_allocations';

    protected $fillable = [
        'transfer_id',
        'product_transfer_id',
        'source_import_stock_layer_id',
        'destination_import_stock_layer_id',
        'transferred_qty',
        'unit_cost',
    ];

    protected $casts = [
        'transferred_qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function transfer()
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }

    public function productTransfer()
    {
        return $this->belongsTo(ProductTransfer::class, 'product_transfer_id');
    }

    public function sourceLayer()
    {
        return $this->belongsTo(ImportStockLayer::class, 'source_import_stock_layer_id');
    }

    public function destinationLayer()
    {
        return $this->belongsTo(ImportStockLayer::class, 'destination_import_stock_layer_id');
    }
}
