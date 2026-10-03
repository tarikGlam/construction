<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingRepairAuditEvent extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['evidence' => 'array', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Accounting repair audit events are immutable.'));
        static::deleting(fn () => throw new LogicException('Accounting repair audit events are immutable.'));
    }
}
