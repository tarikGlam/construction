<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Returns extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $return) {
            if (!$return->isDirty(['grand_total', 'currency_id', 'exchange_rate'])) return;
            // Legacy/manual accounting-only return fixtures have no sale to
            // serialize. Refundable-balance enforcement applies to linked
            // operational sale returns.
            if (!$return->sale_id) return;
            if (\Illuminate\Support\Facades\DB::transactionLevel() === 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['refund_amount' => __('integrity.refund_amount')]);
            }
            app(\App\Services\SalePaymentIntegrity::class)->lockSale((int) $return->sale_id);
            $refunds = Payment::where('return_id', $return->id)->orderBy('id')->lockForUpdate()->get();
            $normalizer = app(\App\Services\Accounting\CurrencyNormalizationService::class);
            $total = '0.0000';
            foreach ($refunds as $refund) {
                if (in_array($refund->accounting_status, ['reversed', 'voided'], true)) continue;
                $total = bcadd($total, $normalizer->normalize($refund->amount, $refund->currency_id, $refund->exchange_rate), 4);
            }
            if (bccomp($total, $normalizer->normalize($return->grand_total, $return->currency_id, $return->exchange_rate), 4) > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['refund_amount' => __('integrity.refund_amount')]);
            }
        });
    }

    use \App\Models\Concerns\ValidatesTransactionExchangeRate;
    use \App\Models\Concerns\ProtectsAccountingCutover;
	use \App\Traits\WarehouseScoped;

	protected $table = 'returns';
    protected $fillable =[
        "reference_no", "user_id", "sale_id", "cash_register_id", "customer_id", "warehouse_id", "biller_id", "account_id", "currency_id", "exchange_rate", "item", "total_qty", "total_discount", "total_tax", "total_price","order_tax_rate", "order_tax", "grand_total", "document", "return_note", "staff_note", "created_at"
    ];

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

    public function user()
    {
    	return $this->belongsTo('App\Models\User');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function products()
    {
         return $this->hasMany('App\Models\ProductReturn','return_id');
    }

    public function refundPayments()
    {
        return $this->hasMany(Payment::class, 'return_id')->whereNotNull('sale_id');
    }

    public function getRefundedAmountAttribute()
    {
        if ($this->relationLoaded('refundPayments')) {
            return $this->refundPayments->sum('amount');
        }

        return Payment::where('return_id', $this->id)->whereNotNull('sale_id')->sum('amount');
    }

    public function getDisplayGrandTotalAttribute()
    {
        return $this->refunded_amount > 0 ? $this->refunded_amount : $this->grand_total;
    }

    public function getRefundPaymentMethodAttribute(): string
    {
        $payments = $this->relationLoaded('refundPayments')
            ? $this->refundPayments
            : $this->refundPayments()->get();
        $methods = $payments->pluck('paying_method')->filter()->unique()->values();

        return $methods->isEmpty() ? __('db.Not recorded') : $methods->implode(', ');
    }

    public function indiaGstSnapshot()
    {
        return $this->hasOne(\Modules\IndiaGST\Entities\IndiaGstReturnSnapshot::class, 'return_id');
    }
}
