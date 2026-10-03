<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class ProjectCost extends Model { protected $guarded=[]; protected $casts=['date'=>'date','amount'=>'decimal:4']; public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} public function category(){return $this->belongsTo(CostCategory::class,'cost_category_id');} }
