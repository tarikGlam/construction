<?php

namespace Modules\Construction\Services;

use Illuminate\Support\Facades\DB;
use App\Services\EmployeeAdvanceService;
use Modules\Project\Entities\Project;

class ProjectFinancialService
{
    public function summary(Project $project): array
    {
        $id = $project->id;
        $direct = (float) DB::table('project_costs')->where('project_id', $id)->sum('amount');
        $materials = (float) DB::table('material_issue_items')
            ->join('material_issues', 'material_issues.id', '=', 'material_issue_items.material_issue_id')
            ->where('material_issues.project_id', $id)
            ->where('material_issues.status', 'issued')
            ->sum('material_issue_items.total_cost')
            - (float) DB::table('material_return_items')
                ->join('material_returns', 'material_returns.id', '=', 'material_return_items.material_return_id')
                ->where('material_returns.project_id', $id)
                ->sum('material_return_items.total_cost');
        $wages = (float) DB::table('project_wages')->where('project_id', $id)->sum('total_amount');
        $rewards = DB::getSchemaBuilder()->hasTable('construction_employee_rewards')
            ? (float) DB::table('construction_employee_rewards')->where('project_id', $id)->where('status', 'approved')->sum('amount')
            : 0.0;
        $labour = $wages + $rewards;
        $employeeExpenses = DB::getSchemaBuilder()->hasTable('construction_employee_expenses')
            ? (float) DB::table('construction_employee_expenses')->where('project_id', $id)->sum('amount')
            : 0.0;
        $advanceSettledExpenses = DB::getSchemaBuilder()->hasTable('construction_employee_advance_settlements')
            ? (float) DB::table('construction_employee_advance_settlements')->where('project_id', $id)->where('settlement_type', 'expense')->sum('amount')
            : 0.0;
        $subcontractPaid = (float) DB::table('subcontractor_contracts')->where('project_id', $id)->sum('paid_amount');
        $subcontractCommitted = (float) DB::table('subcontractor_contracts')->where('project_id', $id)->sum('contract_value');
        $equipment = (float) DB::table('equipment_assignments')->where('project_id', $id)->sum('cost');
        $transport = DB::getSchemaBuilder()->hasTable('construction_transport_records')
            ? (float) DB::table('construction_transport_records')->where('project_id', $id)->where('delivery_status', '!=', 'cancelled')->sum('transport_cost')
            : 0.0;
        $employeeAdvanceOutstanding = app(EmployeeAdvanceService::class)->constructionOutstanding((int) $id);
        $receipts = (float) DB::table('project_receipts')->where('project_id', $id)->sum('amount');
        $postedReceipts = (float) DB::table('project_receipts')->where('project_id', $id)->where('posting_status', 'posted')->sum('amount');
        $total = $direct + $materials + $labour + $employeeExpenses + $advanceSettledExpenses + $subcontractPaid + $equipment + $transport;
        $contract = (float) ($project->contract_value ?? 0);
        $remainingContract = max(0, $contract - $receipts);
        $projectedProfit = $contract - $total;
        $contractMargin = $contract > 0 ? ($projectedProfit / $contract) * 100 : 0;
        $cashPosition = $receipts - $total;

        // Keep the old aliases for compatibility with any existing custom views.
        return compact(
            'direct', 'materials', 'wages', 'rewards', 'labour', 'employeeExpenses', 'advanceSettledExpenses', 'subcontractPaid', 'subcontractCommitted',
            'equipment', 'transport', 'employeeAdvanceOutstanding', 'receipts', 'postedReceipts', 'total', 'contract',
            'remainingContract', 'projectedProfit', 'contractMargin', 'cashPosition'
        ) + [
            'unpostedReceipts' => max(0, $receipts - $postedReceipts),
            'receivable' => $remainingContract,
            'profit' => $projectedProfit,
            'margin' => $contractMargin,
        ];
    }
}
