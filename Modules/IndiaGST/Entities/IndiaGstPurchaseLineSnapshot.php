<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Variant;
use App\Models\Unit;

class IndiaGstPurchaseLineSnapshot extends Model
{
    protected $table = 'india_gst_purchase_line_snapshots';

    protected $fillable = [
        'india_gst_purchase_snapshot_id',
        'product_purchase_id',
        'product_id',
        'variant_id',
        'product_name',
        'product_code',
        'variant_name',
        'classification',
        'hsn_sac_code',
        'uqc_code',
        'purchase_unit_id',
        'purchase_unit_name',
        'quantity',
        'unit_cost',
        'tax_inclusive',
        'gross_value',
        'line_discount',
        'allocated_invoice_discount',
        'taxable_charges',
        'non_taxable_charges',
        'taxable_value',
        'taxability_type',
        'gst_tax_profile_id',
        'gst_tax_profile_code',
        'gst_tax_profile_version',
        'profile_resolution_source',
        'cgst_rate',
        'cgst_amount',
        'sgst_rate',
        'sgst_amount',
        'utgst_rate',
        'utgst_amount',
        'igst_rate',
        'igst_amount',
        'cess_calculation_type',
        'cess_rate',
        'cess_amount_per_unit',
        'cess_amount',
        'is_reverse_charge',
        'itc_eligibility',
        'eligible_itc_amount',
        'ineligible_itc_amount',
        'rounding_adjustment',
        'line_total',
        'calculation_rule_code',
    ];

    protected $casts = [
        'tax_inclusive' => 'boolean',
        'is_reverse_charge' => 'boolean',
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'gross_value' => 'decimal:4',
        'line_discount' => 'decimal:4',
        'allocated_invoice_discount' => 'decimal:4',
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
        'cess_amount_per_unit' => 'decimal:4',
        'cess_amount' => 'decimal:4',
        'eligible_itc_amount' => 'decimal:4',
        'ineligible_itc_amount' => 'decimal:4',
        'rounding_adjustment' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function snapshot()
    {
        return $this->belongsTo(IndiaGstPurchaseSnapshot::class, 'india_gst_purchase_snapshot_id');
    }

    public function productPurchase()
    {
        return $this->belongsTo(ProductPurchase::class, 'product_purchase_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(Variant::class);
    }

    public function purchaseUnit()
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function taxProfile()
    {
        return $this->belongsTo(GstTaxProfile::class, 'gst_tax_profile_id');
    }
}
