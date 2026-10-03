<?php

namespace Modules\Construction\Http\Controllers;

use App\Services\EmployeeAdvanceService;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\SupplierDuePaymentService;
use Illuminate\Support\Facades\DB;
use Modules\Construction\Services\ProjectFinancialService;
use Modules\Project\Entities\Project;

class DashboardController extends Controller
{
    public function __invoke(ProjectFinancialService $financials, SupplierDuePaymentService $supplierDues)
    {
        $projects = Project::with('customer')->latest()->take(6)->get();
        $summaries = $projects->mapWithKeys(fn ($project) => [$project->id => $financials->summary($project)]);
        $allProjects = Project::get();
        $all = $allProjects->map(fn ($project) => $financials->summary($project));

        $supplierPayables = 0.0;
        foreach (Purchase::where('status', '!=', 3)->where('payment_status', 1)->get() as $purchase) {
            $supplierPayables += $supplierDues->dueForPurchase($purchase);
        }

        $kpis = [
            'active_projects' => Project::whereNotIn('project_status', ['completed','cancelled'])->count(),
            'contract_value' => (float) Project::sum('contract_value'),
            'budget' => (float) Project::sum('budget'),
            'current_cost' => (float) $all->sum('total'),
            'receipts' => (float) $all->sum('receipts'),
            'remaining_contract' => (float) $all->sum('remainingContract'),
            'projected_profit' => (float) $all->sum('projectedProfit'),
            'supplier_payables' => $supplierPayables,
            'subcontract_dues' => (float) DB::table('subcontractor_contracts')->where('status','!=','cancelled')->sum(DB::raw('GREATEST(contract_value-paid_amount,0)')),
            'materials_value' => (float) DB::table('product_warehouse')->join('products','products.id','=','product_warehouse.product_id')->sum(DB::raw('product_warehouse.qty * products.cost')),
            'labour_cost' => (float) $all->sum('labour'),
            'employee_advances' => app(EmployeeAdvanceService::class)->totalOutstanding(),
            'equipment_assigned' => DB::table('equipment_assignments')->where('status','assigned')->count(),
            'pending_receipts' => DB::table('product_purchases')->whereColumn('recieved','<','qty')->count(),
        ];
        $recentIssues = DB::table('material_issues')->join('projects','projects.id','=','material_issues.project_id')->select('material_issues.*','projects.title as project_name')->latest('material_issues.id')->take(6)->get();
        $equipment = DB::table('equipment_assignments')->join('equipment','equipment.id','=','equipment_assignments.equipment_id')->join('projects','projects.id','=','equipment_assignments.project_id')->where('equipment_assignments.status','assigned')->select('equipment.name','equipment.code','projects.title as project_name','equipment_assignments.assigned_from')->latest('equipment_assignments.id')->take(6)->get();
        return view('construction::dashboard', compact('projects','summaries','kpis','recentIssues','equipment'));
    }
}
