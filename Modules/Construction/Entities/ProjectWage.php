<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class ProjectWage extends Model { protected $guarded=[]; protected $casts=['period'=>'date','total_amount'=>'decimal:4']; public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} public function employee(){return $this->belongsTo(\App\Models\Employee::class);} }
