<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Adjustment extends Model
{
    use \App\Traits\WarehouseScoped;

    protected $fillable =[
        "reference_no",
        "idempotency_key",
        "idempotency_fingerprint",
        "warehouse_id", 
        "document", 
        "total_qty", 
        "item",
        "note",
        "composition_snapshot"
    ];

    protected $casts = [
        'composition_snapshot' => 'array',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
