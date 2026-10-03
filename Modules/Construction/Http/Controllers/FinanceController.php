<?php

namespace Modules\Construction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Income;
use App\Models\Purchase;
use App\Models\Warehouse;
use App\Services\PaymentAccountService;
use App\Services\InventoryValuationService;
use App\Services\SupplierDuePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Construction\Entities\ConstructionSite;
use Modules\Construction\Entities\SubcontractorContract;
use Modules\Construction\Entities\Shareholder;
use Modules\Construction\Entities\ShareholderTransaction;
use Modules\Construction\Services\ConstructionAccountingService;
use Modules\Project\Entities\Project;

class FinanceController extends Controller
{
    public function paymentsDue(Request $request, SupplierDuePaymentService $supplierDues)
    {
        $projectId = $request->integer('project_id') ?: null;
        $projects = Project::orderBy('title')->get(['id','title']);
        $rows = collect();

        $purchases = Purchase::with(['supplier','project'])->where('status','!=',3)->where('payment_status',1)
            ->when($projectId, fn($q) => $q->where('project_id',$projectId))->orderBy('due_date')->get();
        foreach ($purchases as $purchase) {
            $due = $supplierDues->dueForPurchase($purchase);
            if ($due <= 0.0001) continue;
            $rows->push((object)[
                'type'=>'Supplier Purchase','party'=>$purchase->supplier?->name ?: 'Supplier', 'project'=>$purchase->project?->title,
                'reference'=>$purchase->reference_no, 'due_date'=>$purchase->due_date, 'amount'=>$due,
                'overdue'=>$purchase->due_date && now()->startOfDay()->gt(\Carbon\Carbon::parse($purchase->due_date)->startOfDay()),
            ]);
        }

        $contracts = SubcontractorContract::with(['subcontractor','project'])->where('status','!=','cancelled')
            ->when($projectId, fn($q) => $q->where('project_id',$projectId))->get();
        foreach ($contracts as $contract) {
            $due = max(0,(float)$contract->contract_value-(float)$contract->paid_amount);
            if ($due <= 0.0001) continue;
            $rows->push((object)[
                'type'=>'Subcontractor','party'=>$contract->subcontractor?->name ?: 'Subcontractor','project'=>$contract->project?->title,
                'reference'=>$contract->contract_no,'due_date'=>$contract->end_date,'amount'=>$due,
                'overdue'=>$contract->end_date && now()->startOfDay()->gt(\Carbon\Carbon::parse($contract->end_date)->startOfDay()),
            ]);
        }

        $rows = $rows->sortBy(fn($r) => ($r->overdue ? '0' : '1').($r->due_date ?: '9999-12-31'))->values();
        return view('construction::reports.payments_due', compact('rows','projects','projectId'));
    }

    public function otherRevenue(Request $request)
    {
        $projectId = $request->integer('project_id') ?: null;
        $projects = Project::orderBy('title')->get(['id','title']);
        $rows = Income::with(['warehouse','incomeCategory','project','site'])
            ->when($projectId, fn($q)=>$q->where('project_id',$projectId))
            ->whereNotNull('project_id')->latest()->get();
        return view('construction::reports.other_revenue', compact('rows','projects','projectId'));
    }

    public function inventoryValue(Request $request, InventoryValuationService $valuationService)
    {
        $projectId = $request->integer('project_id') ?: null;
        $siteId = $request->integer('site_id') ?: null;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $projects = Project::orderBy('title')->get(['id','title']);
        $sites = ConstructionSite::with('project')->orderBy('name')->get();
        $warehouses = Warehouse::where('is_active',true)->orderBy('name')->get(['id','name','project_id','site_id','store_type']);
        $warehouseMap = $warehouses->keyBy('id');
        $valuation = $valuationService->currentValuation();
        $items = $valuation['items']->filter(function($item) use($warehouseMap,$projectId,$siteId,$warehouseId){
            $warehouse = $warehouseMap->get($item->warehouse_id);
            if (!$warehouse) return false;
            if ($projectId && (int)$warehouse->project_id !== $projectId) return false;
            if ($siteId && (int)$warehouse->site_id !== $siteId) return false;
            if ($warehouseId && (int)$warehouse->id !== $warehouseId) return false;
            return true;
        })->map(function($item) use($warehouseMap){ $item->constructionWarehouse=$warehouseMap->get($item->warehouse_id); return $item; })->values();
        $totalValue = round((float)$items->sum('value'),4);
        return view('construction::reports.inventory_value', compact('items','totalValue','projects','sites','warehouses','projectId','siteId','warehouseId','valuation'));
    }
    public function shareholders(PaymentAccountService $paymentAccounts)
    {
        $shareholders = Shareholder::with(['transactions.account'])->orderBy('name')->get();
        $transactions = ShareholderTransaction::with(['shareholder', 'account', 'journalEntry'])
            ->latest('transaction_date')->latest('id')->limit(100)->get();
        $accounts = $paymentAccounts->validOperationalAccounts()->sortBy('name')->values();
        $totals = [
            'capital' => round((float) $shareholders->sum(fn ($shareholder) => $shareholder->capital_balance), 4),
            'loans' => round((float) $shareholders->sum(fn ($shareholder) => $shareholder->loan_balance), 4),
        ];

        return view('construction::shareholders', compact('shareholders', 'transactions', 'accounts', 'totals'));
    }

    public function storeShareholder(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:2000',
            'ownership_percentage' => 'nullable|numeric|min:0|max:100',
            'ownership_reference' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string|max:5000',
        ]);
        $data['created_by'] = auth()->id();
        Shareholder::create($data);

        return back()->with('message', 'Shareholder created successfully.');
    }

    public function storeShareholderTransaction(Request $request, ConstructionAccountingService $accounting)
    {
        $data = $request->validate([
            'shareholder_id' => 'required|integer|exists:construction_shareholders,id',
            'transaction_date' => 'required|date',
            'transaction_type' => 'required|in:capital_contribution,capital_withdrawal,loan_received,loan_repayment',
            'amount' => 'required|numeric|gt:0',
            'account_id' => 'required|integer|exists:accounts,id',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);
        $accounting->createShareholderTransaction($data, (int) auth()->id());

        return back()->with('message', 'Shareholder transaction posted successfully.');
    }

}
