<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class EmployeeProjectExpense extends Model { use \App\Traits\WarehouseScoped;
    protected $table='construction_employee_expenses'; protected $guarded=[]; protected $casts=['expense_date'=>'date','amount'=>'decimal:4'];
    public function employee(){return $this->belongsTo(\App\Models\Employee::class);} public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} public function site(){return $this->belongsTo(ConstructionSite::class,'site_id');} public function category(){return $this->belongsTo(CostCategory::class,'cost_category_id');} public function expense(){return $this->belongsTo(\App\Models\Expense::class);} public function warehouse(){return $this->belongsTo(\App\Models\Warehouse::class);} public function account(){return $this->belongsTo(\App\Models\Account::class);}
}
