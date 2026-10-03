<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Models\Expense;
use App\Models\ExpenseCategory;

class IndiaGstExpenseSnapshot extends Model
{
    protected $table = 'india_gst_expense_snapshots';

    protected $fillable = [
        'expense_id',
        'gst_registration_id',
        'expense_category_id',
        'reference_no',
        'expense_date',
        'financial_year',
        'vendor_name',
        'vendor_gstin',
        'vendor_state_code',
        'vendor_state_name',
        'recipient_state_code',
        'recipient_state_name',
        'place_of_supply_state_code',
        'place_of_supply_state_name',
        'jurisdiction_code',
        'is_inter_state',
        'hsn_sac_code',
        'gst_tax_profile_id',
        'gst_rate',
        'is_reverse_charge',
        'is_itc_eligible',
        'rcm_liability',
        'eligible_itc',
        'ineligible_itc',
        'taxable_amount',
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
        'total_tax',
        'total_amount',
        'locked_at',
    ];

    protected $casts = [
        'is_inter_state' => 'boolean',
        'is_reverse_charge' => 'boolean',
        'is_itc_eligible' => 'boolean',
        'gst_rate' => 'decimal:4',
        'rcm_liability' => 'decimal:4',
        'eligible_itc' => 'decimal:4',
        'ineligible_itc' => 'decimal:4',
        'taxable_amount' => 'decimal:4',
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
        'total_tax' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'expense_date' => 'date',
        'locked_at' => 'datetime',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function registration()
    {
        return $this->belongsTo(IndiaGstRegistration::class, 'gst_registration_id');
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function taxProfile()
    {
        return $this->belongsTo(GstTaxProfile::class, 'gst_tax_profile_id');
    }
}
