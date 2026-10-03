<?php

namespace App\Models;

use App\Traits\WarehouseScoped;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use WarehouseScoped;

    protected $fillable =[
        "name", "warehouse_id", "is_active"
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
