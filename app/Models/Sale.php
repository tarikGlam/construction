<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use \App\Models\Concerns\ValidatesTransactionExchangeRate;
    use \App\Models\Concerns\ProtectsAccountingCutover;
    use SoftDeletes, \App\Traits\WarehouseScoped;

    protected $fillable =[
        "reference_no", "idempotency_key", "idempotency_fingerprint", "checkout_token", "user_id", "sales_agent_id", "cash_register_id", "table_id", "queue", "customer_id", "warehouse_id", "biller_id", "item", "total_qty", "total_discount", "total_tax", "total_price", "order_tax_rate", "order_tax", "order_discount_type", "order_discount_value", "order_discount", "coupon_id", "coupon_discount", "shipping_cost", "grand_total", "currency_id", "exchange_rate", "sale_status", "payment_status", "payment_mode", "billing_name", "billing_phone", "billing_email", "billing_address", "billing_city", "billing_state", "billing_country", "billing_zip", "shipping_name", "shipping_phone", "shipping_email", "shipping_address", "shipping_city", "shipping_state","shipping_country","shipping_zip", "sale_type", "pos_workflow", "service_id", "waiter_id", "paid_amount", "document", "sale_note", "staff_note", "created_at", "woocommerce_order_id", "deleted_by",'pay_term_no','pay_term_period','due_date',"repair_id","service_charge",
        "social_channel", "social_campaign", "social_click_id", "ecommerce_inventory_deducted_at", "ecommerce_inventory_restored_at",
        "voided_at", "voided_by", "void_reason", "accounting_status"
    ];

    protected $casts = [
        'voided_at' => 'datetime',
    ];

    public function user()
    {
    	return $this->belongsTo('App\Models\User');
    }

    /**
     * Assigned sales/service staff. This intentionally points to Employee rather
     * than User so staff members do not need SalePro login accounts.
     */
    public function salesAgent()
    {
        return $this->belongsTo(Employee::class, 'sales_agent_id');
    }

    public function products()
    {
        return $this->belongsToMany('App\Models\Product', 'product_sales');
    }

    public function biller()
    {
        return $this->belongsTo('App\Models\Biller');
    }

    public function customer()
    {
        return $this->belongsTo('App\Models\Customer');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function table()
    {
        return $this->belongsTo('App\Models\Table');
    }

    public function currency()
    {
        return $this->belongsTo('App\Models\Currency');
    }

    public function saleWarrantyGuarantees(): HasMany
    {
        return $this->hasMany(SaleWarrantyGuarantee::class);
    }

    public function delivery()
    {
        return $this->hasOne(Delivery::class);
    }

    public function return()
    {
        return $this->hasOne(Returns::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(Returns::class, 'sale_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class)->whereNull('return_id');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by')->withDefault([
            'name' => 'System/Unknown'
        ]);
    }

    public function installmentPlan()
    {
        return $this->morphOne(InstallmentPlan::class, 'reference');
    }
    
    public function serviceJob()
    {
        return $this->belongsTo(\Modules\Repair\Entities\ServiceJob::class, 'repair_id');
    }
}
