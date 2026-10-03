<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class ProjectWage extends Model { protected $guarded=[]; protected $casts=['period'=>'date','rate'=>'decimal:4','basic_amount'=>'decimal:4','overtime_amount'=>'decimal:4','bonus_amount'=>'decimal:4','deduction_amount'=>'decimal:4','total_amount'=>'decimal:4']; public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} public function employee(){return $this->belongsTo(\App\Models\Employee::class);} public function site(){return $this->belongsTo(ConstructionSite::class,'site_id');} public function category(){return $this->belongsTo(CostCategory::class,'cost_category_id');} }
