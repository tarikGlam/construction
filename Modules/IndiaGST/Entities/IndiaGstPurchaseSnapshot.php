<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;

class IndiaGstPurchaseSnapshot extends Model
{
    protected $table = 'india_gst_purchase_snapshots';

    protected $fillable = [
        'purchase_id',
        'gst_registration_id',
        'invoice_reference',
        'invoice_date',
        'transaction_date',
        'financial_year',
        'supplier_id',
        'supplier_name',
        'supplier_legal_name',
        'supplier_trade_name',
        'supplier_gstin',
        'supplier_registration_type',
        'supplier_address',
        'supplier_state_code',
        'supplier_state_name',
        'recipient_legal_name',
        'recipient_trade_name',
        'recipient_gstin',
        'recipient_address',
        'recipient_state_code',
        'recipient_state_name',
        'place_of_supply_state_code',
        'place_of_supply_state_name',
        'supply_rule_code',
        'jurisdiction_code',
        'is_inter_state',
        'is_reverse_charge',
        'rcm_liability_cgst',
        'rcm_liability_sgst',
        'rcm_liability_utgst',
        'rcm_liability_igst',
        'rcm_liability_cess',
        'rcm_total_liability',
        'itc_eligibility',
        'itc_cgst',
        'itc_sgst',
        'itc_utgst',
        'itc_igst',
        'itc_cess',
        'total_eligible_itc',
        'total_ineligible_itc',
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
        'locked_at',
    ];

    protected $casts = [
        'is_inter_state' => 'boolean',
        'is_reverse_charge' => 'boolean',
        'manual_pos_override_used' => 'boolean',
        'rcm_liability_cgst' => 'decimal:4',
        'rcm_liability_sgst' => 'decimal:4',
        'rcm_liability_utgst' => 'decimal:4',
        'rcm_liability_igst' => 'decimal:4',
        'rcm_liability_cess' => 'decimal:4',
        'rcm_total_liability' => 'decimal:4',
        'itc_cgst' => 'decimal:4',
        'itc_sgst' => 'decimal:4',
        'itc_utgst' => 'decimal:4',
        'itc_igst' => 'decimal:4',
        'itc_cess' => 'decimal:4',
        'total_eligible_itc' => 'decimal:4',
        'total_ineligible_itc' => 'decimal:4',
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

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function registration()
    {
        return $this->belongsTo(IndiaGstRegistration::class, 'gst_registration_id');
    }

    public function lines()
    {
        return $this->hasMany(IndiaGstPurchaseLineSnapshot::class, 'india_gst_purchase_snapshot_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function overrideUser()
    {
        return $this->belongsTo(User::class, 'manual_pos_override_user_id');
    }
}
