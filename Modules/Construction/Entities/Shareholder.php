<?php

namespace Modules\Construction\Entities;

use Illuminate\Database\Eloquent\Model;

class Shareholder extends Model
{
    protected $table = 'construction_shareholders';
    protected $guarded = ['id'];

    protected $casts = [
        'ownership_percentage' => 'decimal:4',
    ];

    public function transactions()
    {
        return $this->hasMany(ShareholderTransaction::class, 'shareholder_id');
    }

    public function getCapitalBalanceAttribute(): float
    {
        $transactions = $this->relationLoaded('transactions') ? $this->transactions : $this->transactions()->get();
        return round((float) $transactions->sum(function ($transaction) {
            return match ($transaction->transaction_type) {
                'capital_contribution' => (float) $transaction->amount,
                'capital_withdrawal' => -(float) $transaction->amount,
                default => 0,
            };
        }), 4);
    }

    public function getLoanBalanceAttribute(): float
    {
        $transactions = $this->relationLoaded('transactions') ? $this->transactions : $this->transactions()->get();
        return round((float) $transactions->sum(function ($transaction) {
            return match ($transaction->transaction_type) {
                'loan_received' => (float) $transaction->amount,
                'loan_repayment' => -(float) $transaction->amount,
                default => 0,
            };
        }), 4);
    }
}
