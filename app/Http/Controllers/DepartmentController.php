<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Department;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Auth;
use App\Http\Controllers\Concerns\AuthorizesHrmWarehouse;

class DepartmentController extends Controller
{
    use AuthorizesHrmWarehouse;

    public function index(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('department')) {
            $query = Department::where('is_active', true)->with('warehouse');
            $lims_department_all = $this->applyWarehouseFilter($query, $request)->get();
            $lims_warehouse_list = $this->authorizedWarehouses();
            return view('backend.department.index', compact('lims_department_all', 'lims_warehouse_list'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'warehouse_id' => 'required|integer',
            'name' => [
                'max:255',
                    Rule::unique('departments')->where(function ($query) use ($request) {
                    return $query->where('is_active', 1)->where('warehouse_id', $request->warehouse_id);
                }),
            ],
        ]);

        $data = $request->all();
        $data['warehouse_id'] = $this->authorizedWarehouseId($request);
        $data['is_active'] = true;
        $department = Department::create($data);

        if($request->ajax()){
            return response()->json($department);
        };
        return redirect('departments')->with('message', __('db.Department created successfully'));
    }

    public function update(Request $request, $id)
    {
        $department = Department::findOrFail($request->department_id ?: $id);
        $this->validate($request,[
            'warehouse_id' => 'required|integer',
            'name' => [
                'max:255',
                Rule::unique('departments')->ignore($department->id)->where(function ($query) use ($request) {
                    return $query->where('is_active', 1)->where('warehouse_id', $request->warehouse_id);
                }),
            ],
        ]);

        $data = $request->all();
        $data['warehouse_id'] = $this->authorizedWarehouseId($request);
        $department->update($data);
        return redirect('departments')->with('message', __('db.Department updated successfully'));
    }

    public function deleteBySelection(Request $request)
    {
        $department_id = $request['departmentIdArray'];
        Department::whereIn('id', $department_id)->update(['is_active' => false]);
        return 'Department deleted successfully!';
    }

    public function destroy($id)
    {
        $lims_department_data = Department::findOrFail($id);
        $lims_department_data->is_active = false;
        $lims_department_data->save();
        return redirect('departments')->with('message', __('db.Department deleted successfully'));
    }
}
