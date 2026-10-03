<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Designation;
use Illuminate\Validation\Rule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Auth;
use App\Http\Controllers\Concerns\AuthorizesHrmWarehouse;

class DesignationController extends Controller
{
    use AuthorizesHrmWarehouse;

    public function index(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('department')) {
            $query = Designation::where('is_active', true)->with('warehouse');
            $lims_designation_all = $this->applyWarehouseFilter($query, $request)->get();
            $lims_warehouse_list = $this->authorizedWarehouses();
            return view('backend.hrm.designation.index', compact('lims_designation_all', 'lims_warehouse_list'));
        }
        else
            return redirect()->back()->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
    }

    public function store(Request $request)
    {
        $warehouseId = $this->authorizedWarehouseId($request);
        $this->validate($request, [
            'warehouse_id' => 'required|integer',
            'name' => [
                'required',
                'string',
                'max:255',
                    Rule::unique('designations')->where(function ($query) use ($warehouseId) {
                    return $query->where('is_active', 1)->where('warehouse_id', $warehouseId);
                }),
            ],
        ], ['name.unique' => __('db.designation_already_exists_for_warehouse')]);

        $data = $request->all();
        $data['warehouse_id'] = $warehouseId;
        $data['is_active'] = true;
        try {
            $designation = Designation::create($data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'name' => __('db.designation_already_exists_for_warehouse'),
            ]);
        }
        if($request->ajax()){
            return response()->json($designation);
        }
        return redirect('designations')->with('message', __('db.designation_created_successfully'));
    }

    public function update(Request $request, $id)
    {
        $designation = Designation::findOrFail($request->designation_id ?: $id);
        $warehouseId = $this->authorizedWarehouseId($request);
        $this->validate($request,[
            'warehouse_id' => 'required|integer',
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('designations')->ignore($designation->id)->where(function ($query) use ($warehouseId) {
                    return $query->where('is_active', 1)->where('warehouse_id', $warehouseId);
                }),
            ],
        ], ['name.unique' => __('db.designation_already_exists_for_warehouse')]);

        $data = $request->all();
        $data['warehouse_id'] = $warehouseId;
        try {
            $designation->update($data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'name' => __('db.designation_already_exists_for_warehouse'),
            ]);
        }
        return redirect('designations')->with('message', __('db.designation_updated_successfully'));
    }

    public function deleteBySelection(Request $request)
    {
        $designation_id = $request['designationIdArray'];
        Designation::whereIn('id', $designation_id)->update(['is_active' => false]);
        return 'Designation deleted successfully!';
    }

    public function destroy($id)
    {
        $lims_designation_data = Designation::findOrFail($id);
        $lims_designation_data->is_active = false;
        $lims_designation_data->save();
        return redirect('designations')->with('message', __('db.designation_deleted_successfully'));
    }
}
