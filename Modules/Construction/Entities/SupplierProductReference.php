<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class SupplierProductReference extends Model
{
    protected $guarded=[];
    protected $casts=['last_purchase_price'=>'decimal:4','is_preferred'=>'boolean'];
    public function supplier(){return $this->belongsTo(\App\Models\Supplier::class);}
    public function product(){return $this->belongsTo(\App\Models\Product::class);}
}
