<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    use \App\Traits\WarehouseScoped;

    protected $fillable = [
        "cash_in_hand", "closing_balance", "actual_cash", "user_id", "closed_by_user_id",
        "warehouse_id", "status", "closed_at", "closing_note",
    ];

    protected $casts = [
        'status' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function user()
    {
    	return $this->belongsTo('App\Models\User');
    }

    public function warehouse()
    {
    	return $this->belongsTo('App\Models\Warehouse');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function reconciliation()
    {
        return $this->hasOne(RegisterReconciliation::class);
    }
}
