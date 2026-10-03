<?php

namespace Modules\Construction\Services;

use Illuminate\Support\Facades\DB;
use Modules\Project\Entities\Project;

class ProjectFinancialService
{
    public function summary(Project $project): array
    {
        $id = $project->id;
        $direct = (float) DB::table('project_costs')->where('project_id', $id)->sum('amount');
        $materials = (float) DB::table('material_issue_items')->join('material_issues', 'material_issues.id', '=', 'material_issue_items.material_issue_id')->where('material_issues.project_id', $id)->sum('material_issue_items.total_cost')
            - (float) DB::table('material_return_items')->join('material_returns', 'material_returns.id', '=', 'material_return_items.material_return_id')->where('material_returns.project_id', $id)->sum('material_return_items.total_cost');
        $labour = (float) DB::table('project_wages')->where('project_id', $id)->sum('total_amount');
        $subcontractPaid = (float) DB::table('subcontractor_contracts')->where('project_id', $id)->sum('paid_amount');
        $subcontractCommitted = (float) DB::table('subcontractor_contracts')->where('project_id', $id)->sum('contract_value');
        $equipment = (float) DB::table('equipment_assignments')->where('project_id', $id)->sum('cost');
        $receipts = (float) DB::table('project_receipts')->where('project_id', $id)->sum('amount');
        $total = $direct + $materials + $labour + $subcontractPaid + $equipment;
        $contract = (float) ($project->contract_value ?? 0);
        return compact('direct','materials','labour','subcontractPaid','subcontractCommitted','equipment','receipts','total','contract') + [
            'receivable' => max(0, $contract - $receipts), 'profit' => $contract - $total,
            'margin' => $contract > 0 ? (($contract - $total) / $contract) * 100 : 0,
        ];
    }
}
