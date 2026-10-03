<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class MaterialReturnItem extends Model { protected $guarded=[]; protected $casts=['quantity'=>'decimal:4','unit_cost'=>'decimal:4','total_cost'=>'decimal:4']; public function materialReturn(){return $this->belongsTo(MaterialReturn::class);} }
