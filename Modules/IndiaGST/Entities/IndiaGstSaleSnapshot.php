<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\IndiaGST\Database\factories\IndiaGstSaleSnapshotFactory;

class IndiaGstSaleSnapshot extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'sale_id',
        'gst_registration_id',
        'invoice_reference',
        'invoice_date',
        'transaction_date',
        'financial_year',
        'supplier_legal_name',
        'supplier_trade_name',
        'supplier_gstin',
        'supplier_address',
        'supplier_state_code',
        'supplier_state_name',
        'customer_id',
        'customer_name',
        'customer_legal_name',
        'customer_trade_name',
        'customer_gstin',
        'customer_registration_type',
        'customer_billing_address',
        'customer_shipping_address',
        'customer_state_code',
        'place_of_supply_state_code',
        'place_of_supply_state_name',
        'supply_rule_code',
        'jurisdiction_code',
        'is_inter_state',
        'manual_pos_override_used',
        'manual_pos_override_reason',
        'manual_pos_override_user_id',
        'currency_code',
        'exchange_rate',
        'total_gross_value',
        'total_line_discount',
        'total_invoice_discount',
        'total_taxable_charges',
        'total_non_taxable_charges',
        'total_taxable_value',
        'total_cgst',
        'total_sgst',
        'total_utgst',
        'total_igst',
        'total_cess',
        'rounding_adjustment',
        'grand_total',
        'snapshot_version',
        'locked_at'
    ];

    protected $casts = [
        'is_inter_state' => 'boolean',
        'manual_pos_override_used' => 'boolean',
        'total_gross_value' => 'decimal:4',
        'total_line_discount' => 'decimal:4',
        'total_invoice_discount' => 'decimal:4',
        'total_taxable_value' => 'decimal:4',
        'total_cgst' => 'decimal:4',
        'total_sgst' => 'decimal:4',
        'total_utgst' => 'decimal:4',
        'total_igst' => 'decimal:4',
        'total_cess' => 'decimal:4',
        'rounding_adjustment' => 'decimal:4',
        'grand_total' => 'decimal:4',
        'invoice_date' => 'date',
        'transaction_date' => 'date',
        'locked_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(IndiaGstSaleLineSnapshot::class, 'india_gst_sale_snapshot_id');
    }

    public function sale()
    {
        return $this->belongsTo(\App\Models\Sale::class);
    }

    public function registration()
    {
        return $this->belongsTo(IndiaGstRegistration::class, 'gst_registration_id');
    }
    
    protected static function newFactory(): IndiaGstSaleSnapshotFactory
    {
        //return IndiaGstSaleSnapshotFactory::new();
    }
}
