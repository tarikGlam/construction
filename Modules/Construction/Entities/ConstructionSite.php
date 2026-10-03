<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class ConstructionSite extends Model { protected $guarded=[]; public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} }
