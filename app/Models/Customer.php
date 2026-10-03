<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable =[
        "customer_group_id", "user_id", "name", "company_name",
        "email", "birth_date", "type", "phone_number", "wa_number", "tax_no", "address", "city",
        "state", "postal_code", "country", "opening_balance", "credit_limit", "points", "deposit", "pay_term_no","pay_term_period", "expense", "wishlist", "is_active",
        "zatca_registration_scheme", "zatca_registration_number", "zatca_building_number", "zatca_additional_number", "zatca_district"
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'deposit' => 'decimal:4',
            'expense' => 'decimal:4',
        ];
    }

    public function setDepositAttribute($value)
    {
        $this->attributes['deposit'] = ($value === '' || $value === null || strtolower((string)$value) === 'null') ? null : $value;
    }

    public function setExpenseAttribute($value)
    {
        $this->attributes['expense'] = ($value === '' || $value === null || strtolower((string)$value) === 'null') ? null : $value;
    }

    public function customerGroup()
    {
        return $this->belongsTo('App\Models\CustomerGroup');
    }

    public function indiaGstProfile()
    {
        return $this->hasOne(\Modules\IndiaGST\Entities\IndiaGstCustomerProfile::class, 'customer_id');
    }

    public function user()
    {
    	return $this->belongsTo('App\Models\User');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function discountPlans()
    {
        return $this->belongsToMany('App\Models\DiscountPlan', 'discount_plan_customers');
    }

    public function points(){
        return $this->hasMany(RewardPoint::class,'customer_id');
    }
}
