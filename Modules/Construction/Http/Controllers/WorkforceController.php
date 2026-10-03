<?php

namespace Modules\Construction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Warehouse;
use App\Services\PaymentAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Construction\Entities\ConstructionSite;
use Modules\Construction\Entities\CostCategory;
use Modules\Construction\Entities\EmployeeAdvance;
use Modules\Construction\Entities\EmployeeProjectExpense;
use Modules\Construction\Entities\EmployeeReward;
use Modules\Construction\Entities\ProjectWage;
use Modules\Construction\Services\ConstructionAccountingService;
use Modules\Project\Entities\Project;

class WorkforceController extends Controller
{
    public function index(Request $request, PaymentAccountService $paymentAccounts)
    {
        $filters = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'employee_id' => 'nullable|exists:employees,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
        $wagesQuery = ProjectWage::with(['project','employee','site'])
            ->when($filters['project_id'] ?? null, fn($q,$v)=>$q->where('project_id',$v))
            ->when($filters['employee_id'] ?? null, fn($q,$v)=>$q->where('employee_id',$v))
            ->when($filters['from'] ?? null, fn($q,$v)=>$q->whereDate('period','>=',$v))
            ->when($filters['to'] ?? null, fn($q,$v)=>$q->whereDate('period','<=',$v));

        $summaryBase = clone $wagesQuery;
        $labourByEmployee = (clone $summaryBase)->select('employee_id', DB::raw('SUM(total_amount) total'))
            ->groupBy('employee_id')->with('employee')->orderByDesc('total')->get();
        $labourByProject = (clone $summaryBase)->select('project_id', DB::raw('SUM(total_amount) total'))
            ->groupBy('project_id')->with('project')->orderByDesc('total')->get();

        return view('construction::workforce', [
            'projects' => Project::orderBy('title')->get(),
            'sites' => ConstructionSite::orderBy('name')->get(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(),
            'categories' => CostCategory::where('active', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'accounts' => $paymentAccounts->validOperationalAccounts(),
            'wages' => $wagesQuery->latest('period')->paginate(20)->withQueryString(),
            'rewards' => EmployeeReward::with(['employee','project'])->latest('reward_date')->limit(30)->get(),
            'employeeExpenses' => EmployeeProjectExpense::with(['employee','project','category','expense'])->latest('expense_date')->limit(30)->get(),
            'advances' => EmployeeAdvance::with(['employee','project','settlements'])->latest('advance_date')->limit(30)->get(),
            'labourByEmployee' => $labourByEmployee,
            'labourByProject' => $labourByProject,
            'filters' => $filters,
        ]);
    }

    public function storeWage(Request $request)
    {
        $data = $request->validate([
            'project_id' => 'required|exists:projects,id', 'site_id' => 'nullable|exists:construction_sites,id',
            'employee_id' => 'required|exists:employees,id', 'period' => 'required|date',
            'days_worked' => 'nullable|numeric|min:0', 'rate' => 'nullable|numeric|min:0',
            'rate_basis' => 'nullable|in:daily,monthly,fixed', 'basic_amount' => 'required|numeric|min:0',
            'overtime_amount' => 'nullable|numeric|min:0', 'bonus_amount' => 'nullable|numeric|min:0',
            'deduction_amount' => 'nullable|numeric|min:0', 'cost_category_id' => 'nullable|exists:cost_categories,id',
            'notes' => 'nullable|string',
        ]);
        $data['total_amount'] = (float)$data['basic_amount'] + (float)($data['overtime_amount'] ?? 0) + (float)($data['bonus_amount'] ?? 0) - (float)($data['deduction_amount'] ?? 0);
        if ($data['total_amount'] < 0) return back()->withInput()->withErrors(['deduction_amount'=>'Deductions cannot exceed allocated earnings.']);
        $data['created_by'] = Auth::id();
        ProjectWage::create($data);
        return back()->with('message','Monthly employee/project wage allocation recorded.');
    }

    public function storeReward(Request $request)
    {
        $data = $request->validate([
            'employee_id'=>'required|exists:employees,id','project_id'=>'nullable|exists:projects,id','site_id'=>'nullable|exists:construction_sites,id',
            'reward_date'=>'required|date','reward_type'=>'required|in:bonus,incentive,reward','amount'=>'required|numeric|min:0.0001',
            'status'=>'required|in:pending,approved,cancelled','reference'=>'nullable|string|max:191','notes'=>'nullable|string',
        ]);
        $data['created_by']=Auth::id(); EmployeeReward::create($data);
        return back()->with('message','Employee reward recorded. Approved project rewards are included in project labour cost.');
    }

    public function storeExpense(Request $request, ConstructionAccountingService $accounting)
    {
        $data=$request->validate([
            'employee_id'=>'required|exists:employees,id','project_id'=>'nullable|exists:projects,id','site_id'=>'nullable|exists:construction_sites,id',
            'cost_category_id'=>'required|exists:cost_categories,id','warehouse_id'=>'required|exists:warehouses,id','account_id'=>'required|exists:accounts,id',
            'expense_date'=>'required|date','amount'=>'required|numeric|min:0.0001','purpose'=>'required|string|max:191','reference'=>'nullable|string|max:191',
        ]);
        try { $record=$accounting->createEmployeeProjectExpense($data,(int)Auth::id()); }
        catch(\Throwable $e){ report($e); return back()->withInput()->withErrors(['accounting'=>$e->getMessage()]); }
        return back()->with('message','Employee expense recorded through SalePro Expense/accounting. Status: '.$record->posting_status.'.');
    }

    public function storeAdvance(Request $request, ConstructionAccountingService $accounting)
    {
        $data=$request->validate([
            'employee_id'=>'required|exists:employees,id','project_id'=>'nullable|exists:projects,id','site_id'=>'nullable|exists:construction_sites,id',
            'warehouse_id'=>'required|exists:warehouses,id','account_id'=>'required|exists:accounts,id','advance_date'=>'required|date',
            'amount'=>'required|numeric|min:0.0001','reference'=>'nullable|string|max:191','notes'=>'nullable|string',
        ]);
        try { $record=$accounting->createEmployeeAdvance($data,(int)Auth::id()); }
        catch(\Throwable $e){ report($e); return back()->withInput()->withErrors(['accounting'=>$e->getMessage()]); }
        return back()->with('message','Employee advance issued through the authoritative Employee Advance Receivable accounting flow. Status: '.$record->posting_status.'.');
    }

    public function settleAdvance(Request $request, EmployeeAdvance $advance, ConstructionAccountingService $accounting)
    {
        $data=$request->validate([
            'settlement_date'=>'required|date','settlement_type'=>'required|in:expense,cash_return','amount'=>'required|numeric|min:0.0001',
            'project_id'=>'nullable|exists:projects,id','site_id'=>'nullable|exists:construction_sites,id',
            'cost_category_id'=>'required_if:settlement_type,expense|nullable|exists:cost_categories,id',
            'account_id'=>'required_if:settlement_type,cash_return|nullable|exists:accounts,id','reference'=>'nullable|string|max:191','notes'=>'nullable|string',
        ]);
        try { $accounting->settleEmployeeAdvance($advance,$data,(int)Auth::id()); }
        catch(\Throwable $e){ report($e); return back()->withInput()->withErrors(['accounting'=>$e->getMessage()]); }
        return back()->with('message','Employee advance settlement posted.');
    }
}
