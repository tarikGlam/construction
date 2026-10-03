<?php

namespace App\Models;

use App\Traits\SerializesCashRegisterAttachment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    use \App\Models\Concerns\ProtectsAccountingCutover;
    use HasFactory, SerializesCashRegisterAttachment, \App\Traits\WarehouseScoped;

    protected $fillable =[
        "reference_no", "income_category_id", "warehouse_id", "account_id", "user_id", "cash_register_id", "amount", "note", "created_at"
    ];

    public function warehouse()
    {
    	return $this->belongsTo(Warehouse::class);
    }

    public function incomeCategory() {
    	return $this->belongsTo(IncomeCategory::class);
    }
}
