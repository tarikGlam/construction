<?php

namespace Modules\Construction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use App\Services\WarehouseAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Project\Entities\Project;
use Modules\Construction\Services\ProjectFinancialService;

class ReportController extends Controller
{
    public function profitability(ProjectFinancialService $service)
    {
        $projects = Project::with('customer')->orderBy('title')->get();
        $rows = $projects->map(fn ($p) => ['project' => $p, 'summary' => $service->summary($p)]);
        return view('construction::reports.profitability', compact('rows'));
    }

    public function statement(Request $request, ProjectFinancialService $service)
    {
        $projects = Project::with(['customer','projectManager'])->orderBy('title')->get();
        $project = $request->filled('project_id') ? $projects->firstWhere('id', (int) $request->project_id) : $projects->first();
        $summary = $project ? $service->summary($project) : null;
        return view('construction::reports.statement', compact('projects','project','summary'));
    }

    public function stockMovement(Request $request, StockLedgerService $ledger, WarehouseAccessService $access)
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $projectId = $request->integer('project_id') ?: null;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        if ($access->isRestricted()) $warehouseId = $access->warehouseId();

        $projects = Project::orderBy('title')->get(['id','title']);
        $warehouses = Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id','name']);
        $report = $ledger->report(array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'warehouse_id' => $warehouseId,
            'allowed_warehouse_id' => $access->isRestricted() ? $access->warehouseId() : null,
        ], fn ($v) => $v !== null && $v !== ''));

        $projectMaps = $this->movementProjectMaps();
        $allowedTypes = ['purchase','transfer_out','transfer_in','material_issue','material_return'];
        $movements = $report['movements']->filter(fn ($row) => in_array($row['source_type'], $allowedTypes, true))
            ->map(function ($row) use ($projectMaps) {
                $key = match ($row['source_type']) {
                    'purchase' => 'purchase',
                    'transfer_out', 'transfer_in' => 'transfer',
                    'material_issue' => 'material_issue',
                    'material_return' => 'material_return',
                    default => null,
                };
                $row['project_id'] = $key ? ($projectMaps[$key][$row['source_id']] ?? null) : null;
                $row['project_name'] = $row['project_id'] ? ($projectMaps['project_names'][$row['project_id']] ?? null) : null;
                return $row;
            })
            ->when($projectId, fn ($rows) => $rows->where('project_id', $projectId))
            ->values();

        $pending = DB::table('product_purchases as pp')
            ->join('purchases as p', 'p.id', '=', 'pp.purchase_id')
            ->join('products as product', 'product.id', '=', 'pp.product_id')
            ->join('warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('projects as project', 'project.id', '=', 'p.project_id')
            ->whereNull('p.deleted_at')
            ->whereColumn('pp.recieved', '<', 'pp.qty')
            ->whereBetween(DB::raw('DATE(p.created_at)'), [$startDate, $endDate])
            ->when($warehouseId, fn ($q) => $q->where('p.warehouse_id', $warehouseId))
            ->when($projectId, fn ($q) => $q->where('p.project_id', $projectId))
            ->select(['p.reference_no','p.created_at','p.project_id','project.title as project_name','w.name as warehouse_name','product.name as product_name','product.code as product_code','pp.qty','pp.recieved'])
            ->get();

        $summary = [
            'movement_count' => $movements->count(),
            'qty_in' => (float) $movements->sum('qty_in'),
            'qty_out' => (float) $movements->sum('qty_out'),
            'pending_lines' => $pending->count(),
            'pending_qty' => (float) $pending->sum(fn ($r) => max(0, (float) $r->qty - (float) $r->recieved)),
        ];

        return view('construction::reports.stock_movement', compact('movements','pending','summary','projects','warehouses','projectId','warehouseId','startDate','endDate'));
    }

    public function accountsCashMovement(Request $request, WarehouseAccessService $access)
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $projectId = $request->integer('project_id') ?: null;
        $party = trim((string) $request->input('party', ''));
        $projects = Project::orderBy('title')->get(['id','title']);

        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounting_accounts as aa', 'aa.id', '=', 'jl.accounting_account_id')
            ->leftJoin('projects as project', 'project.id', '=', 'jl.project_id')
            ->leftJoin('construction_sites as site', 'site.id', '=', 'jl.site_id')
            ->leftJoin('cost_categories as category', 'category.id', '=', 'jl.cost_category_id')
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->when($projectId, fn ($q) => $q->where('jl.project_id', $projectId));

        if ($access->isRestricted() && DB::getSchemaBuilder()->hasColumn('journal_entries', 'warehouse_id')) {
            $query->where('je.warehouse_id', $access->warehouseId());
        }

        $rows = $query->select([
            'je.id as journal_entry_id','je.entry_date','je.reference_no','je.source_type','je.source_id','je.event_type','je.note',
            'aa.code as account_code','aa.name as account_name','aa.account_type','jl.debit','jl.credit','jl.description',
            'jl.project_id','project.title as project_name','site.name as site_name','category.name as category_name',
        ])->orderByDesc('je.entry_date')->orderByDesc('je.id')->orderBy('jl.id')->get();

        $partyMap = $this->relatedPartyMap($rows);
        $rows = $rows->map(function ($row) use ($partyMap) {
            $row->party = $partyMap[$row->source_type . ':' . $row->source_id] ?? null;
            return $row;
        });
        if ($party !== '') {
            $needle = mb_strtolower($party);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r->party), $needle))->values();
        }

        $cashLike = $rows->filter(fn ($r) => $r->account_type === 'asset' && preg_match('/cash|bank/i', $r->account_name));
        $summary = [
            'journal_lines' => $rows->count(),
            'debit' => (float) $rows->sum('debit'),
            'credit' => (float) $rows->sum('credit'),
            'cash_bank_in' => (float) $cashLike->sum('debit'),
            'cash_bank_out' => (float) $cashLike->sum('credit'),
        ];

        return view('construction::reports.accounts_cash_movement', compact('rows','summary','projects','projectId','party','startDate','endDate'));
    }

    private function movementProjectMaps(): array
    {
        return [
            'purchase' => DB::table('purchases')->whereNotNull('project_id')->pluck('project_id','id')->map(fn ($v) => (int) $v)->all(),
            'transfer' => DB::table('transfers')->whereNotNull('project_id')->pluck('project_id','id')->map(fn ($v) => (int) $v)->all(),
            'material_issue' => DB::table('material_issues')->pluck('project_id','id')->map(fn ($v) => (int) $v)->all(),
            'material_return' => DB::table('material_returns')->pluck('project_id','id')->map(fn ($v) => (int) $v)->all(),
            'project_names' => DB::table('projects')->pluck('title','id')->all(),
        ];
    }

    private function relatedPartyMap($rows): array
    {
        $map = [];
        $groups = $rows->groupBy('source_type');
        foreach ($groups as $sourceType => $group) {
            $ids = $group->pluck('source_id')->filter()->unique()->values();
            if ($ids->isEmpty()) continue;
            $short = class_basename((string) $sourceType);
            $values = collect();
            if ($short === 'Purchase') {
                $values = DB::table('purchases as p')->leftJoin('suppliers as s','s.id','=','p.supplier_id')->whereIn('p.id',$ids)->pluck('s.name','p.id');
            } elseif ($short === 'Payment') {
                $values = DB::table('payments as p')->leftJoin('sales as sale','sale.id','=','p.sale_id')->leftJoin('customers as c','c.id','=','sale.customer_id')->whereIn('p.id',$ids)->pluck('c.name','p.id');
            } elseif ($short === 'ProjectReceipt') {
                $values = DB::table('project_receipts as pr')->leftJoin('customers as c','c.id','=','pr.customer_id')->whereIn('pr.id',$ids)->pluck('c.name','pr.id');
            } elseif ($short === 'SubcontractorPayment') {
                $values = DB::table('subcontractor_payments as sp')->leftJoin('subcontractors as s','s.id','=','sp.subcontractor_id')->whereIn('sp.id',$ids)->pluck('s.name','sp.id');
            } elseif ($short === 'EmployeeAdvance' || $short === 'EmployeeAdvanceSettlement') {
                $table = $short === 'EmployeeAdvance' ? 'construction_employee_advances' : 'construction_employee_advance_settlements';
                if ($short === 'EmployeeAdvance') {
                    $values = DB::table($table.' as x')->leftJoin('employees as e','e.id','=','x.employee_id')->whereIn('x.id',$ids)->pluck('e.name','x.id');
                } else {
                    $values = DB::table($table.' as x')->join('construction_employee_advances as a','a.id','=','x.employee_advance_id')->leftJoin('employees as e','e.id','=','a.employee_id')->whereIn('x.id',$ids)->pluck('e.name','x.id');
                }
            } elseif ($short === 'ShareholderTransaction') {
                $values = DB::table('construction_shareholder_transactions as st')->leftJoin('construction_shareholders as s','s.id','=','st.shareholder_id')->whereIn('st.id',$ids)->pluck('s.name','st.id');
            }
            foreach ($values as $id => $name) if ($name) $map[$sourceType.':'.$id] = $name;
        }
        return $map;
    }
}
