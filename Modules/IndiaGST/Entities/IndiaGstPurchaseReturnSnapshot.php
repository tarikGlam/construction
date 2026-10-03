<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Models\ReturnPurchase;
use App\Models\Purchase;
use App\Models\Supplier;

class IndiaGstPurchaseReturnSnapshot extends Model
{
    protected $table = 'india_gst_purchase_return_snapshots';

    protected $fillable = [
        'return_purchase_id',
        'purchase_id',
        'india_gst_purchase_snapshot_id',
        'gst_registration_id',
        'adjustment_type',
        'note_reference',
        'note_date',
        'original_invoice_reference',
        'original_invoice_date',
        'financial_year',
        'supplier_id',
        'supplier_name',
        'supplier_gstin',
        'supplier_state_code',
        'recipient_state_code',
        'place_of_supply_state_code',
        'is_inter_state',
        'adjusted_taxable_value',
        'adjusted_cgst',
        'adjusted_sgst',
        'adjusted_utgst',
        'adjusted_igst',
        'adjusted_cess',
        'adjusted_total_tax',
        'reversed_itc_amount',
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
        'reversed_itc_amount' => 'decimal:4',
        'adjusted_grand_total' => 'decimal:4',
        'note_date' => 'date',
        'original_invoice_date' => 'date',
        'locked_at' => 'datetime',
    ];

    public function returnPurchase()
    {
        return $this->belongsTo(ReturnPurchase::class, 'return_purchase_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function purchaseSnapshot()
    {
        return $this->belongsTo(IndiaGstPurchaseSnapshot::class, 'india_gst_purchase_snapshot_id');
    }

    public function registration()
    {
        return $this->belongsTo(IndiaGstRegistration::class, 'gst_registration_id');
    }

    public function lines()
    {
        return $this->hasMany(IndiaGstPurchaseReturnLineSnapshot::class, 'india_gst_purchase_return_snapshot_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
