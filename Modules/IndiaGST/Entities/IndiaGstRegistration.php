<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\IndiaGST\Database\factories\IndiaGstRegistrationFactory;

class IndiaGstRegistration extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];
    
    public function state()
    {
        return $this->belongsTo(IndiaGstState::class, 'state_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(\App\Models\Warehouse::class, 'warehouse_id');
    }
    
    protected static function newFactory(): IndiaGstRegistrationFactory
    {
        //return IndiaGstRegistrationFactory::new();
    }
}
