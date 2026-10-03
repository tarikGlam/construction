<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class RegisterReconciliation extends Model
{
    protected $fillable = [
        'cash_register_id', 'warehouse_id', 'opened_by_user_id', 'closed_by_user_id',
        'opened_at', 'closed_at', 'opening_cash', 'expected_total', 'counted_total',
        'variance_total', 'closing_note',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_cash' => 'decimal:4',
        'expected_total' => 'decimal:4',
        'counted_total' => 'decimal:4',
        'variance_total' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Closed register reconciliations are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Closed register reconciliations are immutable.'));
    }

    public function register()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function tenders()
    {
        return $this->hasMany(RegisterReconciliationTender::class)->orderBy('method_label');
    }
}
