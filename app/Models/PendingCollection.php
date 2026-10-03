<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingCollection extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:4',
        'collected_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->collected_at)) {
                $model->collected_at = now();
            }
        });

        static::deleting(function ($model) {
            throw new \Exception('Pending collection records are immutable audit logs and cannot be deleted.');
        });
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function collectedBy()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function handedOverTo()
    {
        return $this->belongsTo(User::class, 'handed_over_to');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
