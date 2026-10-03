<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RewardPoint extends Model
{
    use HasFactory;
    protected $table = 'reward_points';
     protected $fillable = [
        'customer_id',
        'reward_point_type',
        'event_type',
        'event_key',
        'points',
        'deducted_points',
        'note',
        'expired_at',
        'created_by',
        'updated_by',
        'sale_id',
        'payment_id',
        'return_id',
        'source_amount',
        'conversion_rate',
        'balance_after',
        'reward_point_setting_id',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'deducted_points' => 'decimal:2',
        'source_amount' => 'decimal:4',
        'conversion_rate' => 'decimal:8',
        'balance_after' => 'decimal:4',
        'expired_at' => 'datetime',
    ];

    public function customer(){
        return $this->belongsTo(Customer::class,'customer_id');
    }

    public function user(){
        return $this->belongsTo(User::class,'created_by');
    }

    protected static function boot(){
        parent::boot();
        static::creating(function ($query){
            $query->created_by = auth()->id();
        });
    }
}
