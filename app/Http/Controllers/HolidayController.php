<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Holiday;
use Auth;
use User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Mail;
use App\Mail\HolidayApprove;
use App\Models\MailSetting;
use App\Http\Controllers\Concerns\AuthorizesHrmWarehouse;

class HolidayController extends Controller
{
    use \App\Traits\MailInfo, AuthorizesHrmWarehouse;

    public function index(Request $request)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('holiday')) {
            $approve_permission = true;
            $holidayQuery = Holiday::with('warehouse')->orderBy('id', 'desc');
            $lims_holiday_list = $this->applyHolidayFilter($holidayQuery, $request)->get();
        }
        else {
            $approve_permission = false;
            $lims_holiday_list = Holiday::with('warehouse')->where('user_id', Auth::id())->orderBy('id', 'desc')->get();
        }

        $lims_warehouse_list = $this->authorizedWarehouses();
        $supports_global_warehouse_filter = true;
        return view('backend.hrm.holiday.index', compact('lims_holiday_list', 'approve_permission', 'lims_warehouse_list', 'supports_global_warehouse_filter'));
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $warehouseId = $this->authorizedHolidayWarehouseId($request);
        $data = [
            'from_date'   => date("Y-m-d", strtotime(str_replace("/", "-", $request->input('from_date')))),
            'to_date'     => date("Y-m-d", strtotime(str_replace("/", "-", $request->input('to_date')))),
            'user_id'     => Auth::id(),
            'warehouse_id' => $warehouseId,
            'note'        => $request->input('note'),
            'recurring' => $request->input('recurring') ?? 0,
            'region' => $request->input('region') ?? null
        ];

        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('holiday')) {
            $data['is_approved'] = true;
        }
        else{
            $data['is_approved'] = false;
        }
        Holiday::create($data);
        return redirect()->back()->with('message', __("db.Holiday created successfully"));
    }

    public function show($id)
    {
        //
    }

    public function approveHoliday($id)
    {
        $holiday = Holiday::findOrFail($id);
        $holiday->is_approved = true;
        $holiday->save();
        //collecting mail data
        $mail_data['name'] = $holiday->user->name;
        $mail_data['email'] = $holiday->user->email;
        $mail_setting = MailSetting::latest()->first();
        if($mail_setting) {
            $this->setMailInfo($mail_setting);
            try {
                Mail::to($mail_data['email'])->send(new HolidayApprove($mail_data));
                return 'Holiday approved successfully!';
            }
            catch(\Exception $e) {
                return 'Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
    }

    public function myHoliday($year, $month)
    {
        $start = 1;
        $number_of_day = cal_days_in_month(CAL_GREGORIAN,$month,$year);
        while($start <= $number_of_day)
        {
            if($start < 10)
                $date = $year.'-'.$month.'-0'.$start;
            else
                $date = $year.'-'.$month.'-'.$start;
            $holiday_found = Holiday::whereDate('from_date','<=', $date)
                ->whereDate('to_date','>=', $date)
                ->where('is_approved', true)
                ->first();
            if($holiday_found) {
                $general_setting = \App\Models\GeneralSetting::select('date_format')->latest()->first();
                $holidays[$start] = date($general_setting->date_format, strtotime($holiday_found->from_date)).' '.__("db.To").' '.date($general_setting->date_format, strtotime($holiday_found->to_date));
            }
            else {
                $holidays[$start] = false;
            }
            $start++;
        }
        //return dd($holidays);
        $start_day = date('w', strtotime($year.'-'.$month.'-01')) + 1;
        $prev_year = date('Y', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
        $prev_month = date('m', strtotime('-1 month', strtotime($year.'-'.$month.'-01')));
        $next_year = date('Y', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
        $next_month = date('m', strtotime('+1 month', strtotime($year.'-'.$month.'-01')));
        return view('backend.hrm.holiday.my_holiday', compact('start_day', 'year', 'month', 'number_of_day', 'prev_year', 'prev_month', 'next_year', 'next_month', 'holidays'));
    }

    public function update(Request $request, $id)
    {
        $holiday_data = Holiday::findOrFail($request->input('id') ?: $id);
        $warehouseId = $this->authorizedHolidayWarehouseId($request);
        $data = [
            'from_date'   => date("Y-m-d", strtotime(str_replace("/", "-", $request->input('from_date')))),
            'to_date'     => date("Y-m-d", strtotime(str_replace("/", "-", $request->input('to_date')))),
            'note'        => $request->input('note'),
            'warehouse_id' => $warehouseId,
            'recurring' => $request->input('recurring') ?? 0,
            'region' => $request->input('region') ?? null
        ];
        $holiday_data->update($data);
        return redirect()->back()->with('message', __("db.Holiday updated successfully"));
    }

    public function deleteBySelection(Request $request)
    {
        $holiday_id = $request['holidayIdArray'];
        $holidays = Holiday::whereIn('id', $holiday_id)->get();
        foreach ($holidays as $holiday) {
            $holiday->delete();
        }
        return 'Holiday deleted successfully!';
    }

    public function destroy($id)
    {
        Holiday::findOrFail($id)->delete();
        return redirect()->back()->with('not_prmitted', __("db.Holiday deleted successfully"));
    }

    private function authorizedHolidayWarehouseId(Request $request): ?int
    {
        if (!$request->filled('warehouse_id')) {
            abort_unless($this->warehouseAccess()->isGlobal(), 403, 'Only a global administrator may manage global holidays.');
            return null;
        }

        return $this->authorizedWarehouseId($request);
    }

    private function applyHolidayFilter($query, Request $request)
    {
        if (!$request->filled('warehouse_id')) {
            return $query;
        }

        if ($request->input('warehouse_id') === 'global') {
            abort_unless($this->warehouseAccess()->isGlobal(), 403, 'Warehouse access denied.');
            return $query->whereNull('warehouse_id');
        }

        return $query->where('warehouse_id', $this->authorizedWarehouseId($request));
    }
}
