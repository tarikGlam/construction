<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class EmployeeAdvance extends Model { use \App\Traits\WarehouseScoped;
    protected $table='construction_employee_advances'; protected $guarded=[]; protected $casts=['advance_date'=>'date','amount'=>'decimal:4','settled_expense_amount'=>'decimal:4','cash_returned_amount'=>'decimal:4','outstanding_amount'=>'decimal:4'];
    public function employee(){return $this->belongsTo(\App\Models\Employee::class);} public function project(){return $this->belongsTo(\Modules\Project\Entities\Project::class);} public function site(){return $this->belongsTo(ConstructionSite::class,'site_id');} public function expense(){return $this->belongsTo(\App\Models\Expense::class);} public function settlements(){return $this->hasMany(EmployeeAdvanceSettlement::class,'employee_advance_id');}
}
