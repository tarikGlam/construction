<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class Equipment extends Model { protected $table='equipment'; protected $guarded=[]; protected $casts=['acquisition_date'=>'date','acquisition_value'=>'decimal:4']; public function assignments(){return $this->hasMany(EquipmentAssignment::class);} }
