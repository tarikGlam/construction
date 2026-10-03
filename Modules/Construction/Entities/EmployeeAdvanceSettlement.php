<?php
namespace Modules\Construction\Entities;
use Illuminate\Database\Eloquent\Model;
class EmployeeAdvanceSettlement extends Model {
    protected $table='construction_employee_advance_settlements'; protected $guarded=[]; protected $casts=['settlement_date'=>'date','amount'=>'decimal:4'];
    public function advance(){return $this->belongsTo(EmployeeAdvance::class,'employee_advance_id');} public function expense(){return $this->belongsTo(\App\Models\Expense::class);} public function category(){return $this->belongsTo(CostCategory::class,'cost_category_id');}
}
