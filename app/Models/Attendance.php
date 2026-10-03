<?php

namespace App\Models;

use App\Traits\EmployeeWarehouseScoped;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use EmployeeWarehouseScoped;

    protected $fillable =[
        "date", "employee_id", "user_id",
        "checkin", "checkout", "status", "note"
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
