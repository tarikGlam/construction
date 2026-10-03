<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use \App\Models\Concerns\ProtectsAccountingCutover;
    protected $fillable =[
        "amount", "deposit_type", "account_id", "customer_id", "user_id", "warehouse_id", "request_token", "note", "accounting_status"
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
