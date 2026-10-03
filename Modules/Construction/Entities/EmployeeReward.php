<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class EmployeeReward extends Model {
    protected $table='construction_employee_rewards'; protected $guarded=[]; protected $casts=['reward_date'=>'date','amount'=>'decimal:4'];
    public function employee(){return $this->belongsTo(\App\Models\Employee::class);} public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} public function site(){return $this->belongsTo(ConstructionSite::class,'site_id');}
}
