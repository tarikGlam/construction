<?php

namespace Modules\IndiaGST\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Models\Supplier;

class IndiaGstSupplierProfile extends Model
{
    protected $table = 'india_gst_supplier_profiles';

    protected $fillable = [
        'supplier_id',
        'registration_type',
        'gstin',
        'state_id',
        'is_sez',
        'pan',
    ];

    protected $casts = [
        'is_sez' => 'boolean',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function state()
    {
        return $this->belongsTo(IndiaGstState::class, 'state_id');
    }
}
