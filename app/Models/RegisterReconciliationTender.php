<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class RegisterReconciliationTender extends Model
{
    protected $fillable = [
        'register_reconciliation_id', 'method_key', 'method_label', 'expected_amount',
        'counted_amount', 'variance_amount', 'variance_reason',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:4',
        'counted_amount' => 'decimal:4',
        'variance_amount' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Closed register tender snapshots are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Closed register tender snapshots are immutable.'));
    }

    public function reconciliation()
    {
        return $this->belongsTo(RegisterReconciliation::class, 'register_reconciliation_id');
    }
}
