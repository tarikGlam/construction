<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;
use App\Models\ProductReturn;

class IndiaGstReturnLineSnapshot extends Model
{
    protected $table = 'india_gst_return_line_snapshots';

    protected $fillable = [
        'india_gst_return_snapshot_id',
        'product_return_id',
        'product_id',
        'hsn_sac_code',
        'quantity',
        'unit_price',
        'taxable_value',
        'cgst_rate',
        'cgst_amount',
        'sgst_rate',
        'sgst_amount',
        'utgst_rate',
        'utgst_amount',
        'igst_rate',
        'igst_amount',
        'cess_rate',
        'cess_amount',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'taxable_value' => 'decimal:4',
        'cgst_rate' => 'decimal:4',
        'cgst_amount' => 'decimal:4',
        'sgst_rate' => 'decimal:4',
        'sgst_amount' => 'decimal:4',
        'utgst_rate' => 'decimal:4',
        'utgst_amount' => 'decimal:4',
        'igst_rate' => 'decimal:4',
        'igst_amount' => 'decimal:4',
        'cess_rate' => 'decimal:4',
        'cess_amount' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function snapshot()
    {
        return $this->belongsTo(IndiaGstReturnSnapshot::class, 'india_gst_return_snapshot_id');
    }

    public function productReturn()
    {
        return $this->belongsTo(ProductReturn::class, 'product_return_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
