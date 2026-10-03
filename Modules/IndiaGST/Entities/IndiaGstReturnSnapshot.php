<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Models\Returns;
use App\Models\Sale;
use App\Models\Customer;

class IndiaGstReturnSnapshot extends Model
{
    protected $table = 'india_gst_return_snapshots';

    protected $fillable = [
        'return_id',
        'sale_id',
        'india_gst_sale_snapshot_id',
        'gst_registration_id',
        'adjustment_type',
        'note_reference',
        'note_date',
        'original_invoice_reference',
        'original_invoice_date',
        'financial_year',
        'customer_id',
        'customer_name',
        'customer_gstin',
        'customer_state_code',
        'place_of_supply_state_code',
        'is_inter_state',
        'adjusted_taxable_value',
        'adjusted_cgst',
        'adjusted_sgst',
        'adjusted_utgst',
        'adjusted_igst',
        'adjusted_cess',
        'adjusted_total_tax',
        'adjusted_grand_total',
        'locked_at',
    ];

    protected $casts = [
        'is_inter_state' => 'boolean',
        'adjusted_taxable_value' => 'decimal:4',
        'adjusted_cgst' => 'decimal:4',
        'adjusted_sgst' => 'decimal:4',
        'adjusted_utgst' => 'decimal:4',
        'adjusted_igst' => 'decimal:4',
        'adjusted_cess' => 'decimal:4',
        'adjusted_total_tax' => 'decimal:4',
        'adjusted_grand_total' => 'decimal:4',
        'note_date' => 'date',
        'original_invoice_date' => 'date',
        'locked_at' => 'datetime',
    ];

    public function return()
    {
        return $this->belongsTo(Returns::class, 'return_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleSnapshot()
    {
        return $this->belongsTo(IndiaGstSaleSnapshot::class, 'india_gst_sale_snapshot_id');
    }

    public function registration()
    {
        return $this->belongsTo(IndiaGstRegistration::class, 'gst_registration_id');
    }

    public function lines()
    {
        return $this->hasMany(IndiaGstReturnLineSnapshot::class, 'india_gst_return_snapshot_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
