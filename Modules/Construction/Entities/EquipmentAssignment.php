<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class EquipmentAssignment extends Model { protected $guarded=[]; protected $casts=['assigned_from'=>'date','assigned_to'=>'date','cost'=>'decimal:4']; public function equipment(){return $this->belongsTo(Equipment::class);} public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} }
