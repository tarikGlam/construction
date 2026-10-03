<?php

namespace App\Models;

use App\Traits\SerializesCashRegisterAttachment;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use \App\Models\Concerns\ValidatesTransactionExchangeRate;
    public function save(array $options = [])
    {
        if (!$this->sale_id || ($this->exists && !$this->isDirty(['amount', 'change', 'paying_method', 'sale_id', 'return_id', 'currency_id', 'exchange_rate']))) {
            return $this->saveWithRegisterLock($options);
        }
        return \Illuminate\Support\Facades\DB::transaction(function () use ($options) {
            $integrity = app(\App\Services\SalePaymentIntegrity::class);
            $sale = $integrity->lockSale((int) $this->sale_id);
            $this->amount = $integrity->money($this->amount);
            $this->change = $integrity->money($this->change ?? 0);
            if (!$this->currency_id) $this->currency_id = $sale->currency_id;
            $baseCurrencyId = app(\App\Services\Accounting\CurrencyNormalizationService::class)->getBaseCurrencyId();
            $rawRate = $this->getAttributes()['exchange_rate'] ?? $sale->exchange_rate;
            if ($rawRate === null && (!$this->currency_id || (int) $this->currency_id === $baseCurrencyId)) {
                $rawRate = '1.00000000';
            }
            $this->exchange_rate = app(\App\Services\TransactionExchangeRate::class)->validate($rawRate);
            $integrity->validate($this, $sale);
            $saved = $this->saveWithRegisterLock($options);
            $integrity->refreshTotals($sale);
            return $saved;
        });
    }

    use \App\Models\Concerns\ProtectsAccountingCutover;
    use SerializesCashRegisterAttachment {
        save as private saveWithRegisterLock;
    }

    protected $fillable =[
        "purchase_id", "user_id", "sale_id", "pending_collection_id", "return_id", "purchase_return_id", "cash_register_id", "account_id","payment_receiver", "payment_reference", "amount", "currency_id", "installment_id", "exchange_rate", "payment_at", "used_points", "change", "paying_method", "payment_proof", "document", "payment_note","service_job_id", "accounting_status"
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:8',
        'payment_at' => 'datetime',
    ];

    protected function closedRegisterAttachmentMessage(): string
    {
        return __('db.The cash register closed before this payment could be recorded. Reopen the register and retry.');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function account()
    {
        return $this->belongsTo(\App\Models\Account::class, 'account_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function cheque()
    {
        return $this->hasOne(PaymentWithCheque::class, 'payment_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function saleReturn()
    {
        return $this->belongsTo(Returns::class, 'return_id');
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(ReturnPurchase::class, 'purchase_return_id');
    }

    /**
     * Resolve related party information strictly from in-memory preloaded relationships.
     * Guaranteed to never trigger lazy-loading queries or DB lookups.
     *
     * @return array{type: string|null, name: string|null, phone: string|null}
     */
    public function getPartyDetailsAttribute(): array
    {
        // 1. Sale Payment (and no return_id)
        if ($this->sale_id && !$this->return_id) {
            $sale = $this->relationLoaded('sale') ? $this->getRelation('sale') : null;
            $customer = $sale && $sale->relationLoaded('customer') ? $sale->getRelation('customer') : null;
            if ($customer && !empty($customer->name)) {
                return [
                    'type' => 'Customer',
                    'name' => $customer->name,
                    'phone' => $customer->phone_number,
                ];
            }
        }

        // 2. Purchase Payment (and no purchase_return_id)
        if ($this->purchase_id && !$this->purchase_return_id) {
            $purchase = $this->relationLoaded('purchase') ? $this->getRelation('purchase') : null;
            $supplier = $purchase && $purchase->relationLoaded('supplier') ? $purchase->getRelation('supplier') : null;
            if ($supplier && !empty($supplier->name)) {
                return [
                    'type' => 'Supplier',
                    'name' => $supplier->name,
                    'phone' => $supplier->phone_number,
                ];
            }
        }

        // 3. Sale Return Payment
        if ($this->return_id) {
            $return = $this->relationLoaded('saleReturn') ? $this->getRelation('saleReturn') : null;
            $customer = $return && $return->relationLoaded('customer') ? $return->getRelation('customer') : null;
            if (!$customer) {
                $sale = $this->relationLoaded('sale') ? $this->getRelation('sale') : null;
                $customer = $sale && $sale->relationLoaded('customer') ? $sale->getRelation('customer') : null;
            }
            if ($customer && !empty($customer->name)) {
                return [
                    'type' => 'Customer',
                    'name' => $customer->name,
                    'phone' => $customer->phone_number,
                ];
            }
        }

        // 4. Purchase Return Payment
        if ($this->purchase_return_id) {
            $return = $this->relationLoaded('purchaseReturn') ? $this->getRelation('purchaseReturn') : null;
            $supplier = $return && $return->relationLoaded('supplier') ? $return->getRelation('supplier') : null;
            if (!$supplier) {
                $purchase = $this->relationLoaded('purchase') ? $this->getRelation('purchase') : null;
                $supplier = $purchase && $purchase->relationLoaded('supplier') ? $purchase->getRelation('supplier') : null;
            }
            if ($supplier && !empty($supplier->name)) {
                return [
                    'type' => 'Supplier',
                    'name' => $supplier->name,
                    'phone' => $supplier->phone_number,
                ];
            }
        }

        return [
            'type' => null,
            'name' => null,
            'phone' => null,
        ];
    }

    public function getReportTransactionTypeAttribute(): string
    {
        if ($this->purchase_return_id) {
            return 'Supplier Refund';
        }
        if ($this->return_id) {
            return 'Customer Refund';
        }
        if ($this->purchase_id) {
            return 'Supplier Payment';
        }
        if ($this->sale_id) {
            return 'Customer Payment';
        }

        return 'Other Payment';
    }

    public function pendingCollection()
    {
        return $this->belongsTo(PendingCollection::class, 'pending_collection_id');
    }
}
