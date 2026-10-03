<?php

namespace Modules\Project\Entities;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use App\Models\Employee;

class Project extends Model
{
	protected $fillable = [
		'title','customer_id','project_category_id','start_date','end_date','project_priority','description','summary','project_status','project_note','is_notify','added_by','project_progress',
		'project_code','location','project_manager_id','contract_value','budget','expected_end_date','actual_end_date'
	];

	protected $casts = [
		'contract_value' => 'decimal:4',
		'budget' => 'decimal:4',
		'expected_end_date' => 'date',
		'actual_end_date' => 'date',
	];

	public function category(){
		return $this->belongsTo('Modules\Project\Entities\ProjectCategory', 'project_category_id');
	}
	public function customer(){
		return $this->hasOne('App\Models\Customer','id','customer_id');
	}
	public function addedBy(){
		return $this->hasOne('App\Models\User','id','added_by');
	}
	public function assignedEmployees(){
		return $this->belongsToMany(Employee::class, 'employee_project', 'project_id', 'employee_id');
	}
	public function projectManager(){
		return $this->belongsTo(Employee::class, 'project_manager_id');
	}

	public function setStartDateAttribute($value)
	{
		try {
			$this->attributes['start_date'] = \Carbon\Carbon::createFromFormat(getDateFormat(), $value)->format('Y-m-d');
		} catch (\Exception $e) {
			$this->attributes['start_date'] = \Carbon\Carbon::parse($value)->format('Y-m-d');
		}
	}

	public function getStartDateAttribute($value)
	{
		return Carbon::parse($value)->format(getDateFormat());
	}

	public function setEndDateAttribute($value)
	{
		try {
			$this->attributes['end_date'] = \Carbon\Carbon::createFromFormat(getDateFormat(), $value)->format('Y-m-d');
		} catch (\Exception $e) {
			$this->attributes['end_date'] = \Carbon\Carbon::parse($value)->format('Y-m-d');
		}
	}

	public function getEndDateAttribute($value)
	{
		return Carbon::parse($value)->format(getDateFormat());
	}

}
