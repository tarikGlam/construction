<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosPaymentAttempt extends Model
{
    protected $table = 'pos_payment_attempts';

    protected $fillable = [
        'attempt_uuid',
        'gateway',
        'method',
        'order_id',
        'payment_id',
        'expected_amount',
        'currency',
        'state',
        'sale_id',
        'payment_record_id',
        'idempotency_key',
        'customer_id',
        'warehouse_id',
        'user_id',
        'sale_context',
        'verification_data',
        'failure_reason',
        'verified_at',
        'finalized_at',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:4',
        'sale_context' => 'array',
        'verification_data' => 'array',
        'verified_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_record_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isVerified(): bool
    {
        return in_array($this->state, ['verified', 'finalized'], true);
    }

    public function isFinalized(): bool
    {
        return $this->state === 'finalized';
    }

    public function canBeVerified(): bool
    {
        return in_array($this->state, ['initiated', 'pending'], true);
    }

    public function markVerified(string $paymentId, array $verificationData = []): bool
    {
        if ($this->isFinalized()) {
            return true;
        }

        $this->state = 'verified';
        $this->payment_id = $paymentId;
        $this->verification_data = $verificationData;
        $this->verified_at = now();
        return $this->save();
    }

    public function markFinalized(int $saleId, ?int $paymentRecordId = null): bool
    {
        $this->state = 'finalized';
        $this->sale_id = $saleId;
        if ($paymentRecordId) {
            $this->payment_record_id = $paymentRecordId;
        }
        $this->finalized_at = now();
        return $this->save();
    }

    public function markFailed(string $reason): bool
    {
        if ($this->isFinalized()) {
            return false; // Monotonic state: never regress finalized to failed
        }

        $this->state = 'failed';
        $this->failure_reason = $reason;
        return $this->save();
    }
}
