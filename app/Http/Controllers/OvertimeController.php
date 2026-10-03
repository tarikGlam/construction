<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Overtime;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Concerns\AuthorizesHrmWarehouse;

class OvertimeController extends Controller
{
    use AuthorizesHrmWarehouse;

    public function index(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
            if ($role->hasPermissionTo('overtime')) {
                $overtimeQuery = Overtime::with('employee.warehouse');
                if ($request->filled('warehouse_id')) {
                    $warehouseId = $this->authorizedWarehouseId($request);
                    $overtimeQuery->whereHas('employee', fn ($query) => $query->where('warehouse_id', $warehouseId));
                }
                $overtimes = $overtimeQuery->get();
                $employees = Employee::all();
                $lims_warehouse_list = $this->authorizedWarehouses();
                return view('backend.hrm.overtime.index', compact('overtimes', 'employees', 'lims_warehouse_list'));
            } else {
                return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
            }
    }

    public function store(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'hours' => 'required|numeric|min:0',
            'rate' => 'required|numeric|min:0'
        ]);
        $this->authorizedEmployee((int) $request->employee_id);
        $data = $request->all();
        $data['date'] =  date("Y-m-d", strtotime(str_replace("/", "-", $request->input('date'))));
        Overtime::create($data);
        return redirect()->back()->with('message', 'Overtime added successfully');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'hours' => 'required|numeric|min:0',
            'rate' => 'required|numeric|min:0',
            'status' => 'required|in:pending,approved,rejected'
        ]);
        $this->authorizedEmployee((int) $request->employee_id);
        $data = $request->all();
        $data['date'] =  date("Y-m-d", strtotime(str_replace("/", "-", $request->input('date'))));
        $overtime = Overtime::findOrFail($id);
        $overtime->update($data);

        return redirect()->back()->with('message', 'Overtime updated successfully');
    }

    public function destroy($id)
    {
        $overtime = Overtime::findOrFail($id);
        $overtime->delete();
        return redirect()->back()->with('message', 'Overtime deleted successfully');
    }
}
