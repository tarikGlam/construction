@extends('backend.layout.main')
@section('construction-title','Project Statement')
@section('content')
<div class="container-fluid">
    @include('construction::partials.header')
    <form method="get" class="construction-form">
        <div class="row align-items-end">
            <div class="col-md-8"><label>Project</label><select name="project_id" class="form-control">@foreach($projects as $p)<option value="{{$p->id}}" @selected($project && $project->id===$p->id)>{{$p->project_code}} · {{$p->title}}</option>@endforeach</select></div>
            <div class="col-md-2"><button class="btn btn-primary btn-block">View Statement</button></div>
            <div class="col-md-2"><button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-block">Print</button></div>
        </div>
    </form>

    @if($project)
        <div class="construction-table">
            <div class="row">
                <div class="col-md-8"><h3>{{$project->title}}</h3><p class="text-muted">{{$project->project_code}} · {{$project->location}}</p></div>
                <div class="col-md-4 text-md-right"><strong>Status: {{ucfirst($project->project_status)}}</strong><br>Client: {{optional($project->customer)->name ?: '—'}}</div>
            </div>
            <hr>
            <div class="row">
                <div class="col-md-3"><small>Project Manager</small><div>{{optional($project->projectManager)->name ?: '—'}}</div></div>
                <div class="col-md-3"><small>Start Date</small><div>{{$project->start_date}}</div></div>
                <div class="col-md-3"><small>Expected End</small><div>{{$project->expected_end_date?->format('Y-m-d') ?: $project->end_date}}</div></div>
                <div class="col-md-3"><small>Budget</small><div>{{number_format($project->budget,2)}}</div></div>
            </div>
            <hr>
            <div class="construction-grid">
                <div class="card construction-kpi"><div class="card-body"><small>Contract Value</small><div class="value">{{number_format($summary['contract'],2)}}</div></div></div>
                <div class="card construction-kpi"><div class="card-body"><small>Receipts / Advances</small><div class="value">{{number_format($summary['receipts'],2)}}</div></div></div>
                <div class="card construction-kpi"><div class="card-body"><small>Remaining Contract Amount</small><div class="value">{{number_format($summary['remainingContract'],2)}}</div></div></div>
                <div class="card construction-kpi"><div class="card-body"><small>Total Actual Cost</small><div class="value">{{number_format($summary['total'],2)}}</div></div></div>
                <div class="card construction-kpi"><div class="card-body"><small>Cash Position</small><div class="value">{{number_format($summary['cashPosition'],2)}}</div></div></div>
                <div class="card construction-kpi"><div class="card-body"><small>Projected Contract Profit</small><div class="value">{{number_format($summary['projectedProfit'],2)}}</div></div></div>
                <div class="card construction-kpi"><div class="card-body"><small>Contract Margin</small><div class="value">{{number_format($summary['contractMargin'],1)}}%</div></div></div>
            </div>

            <h4 class="mt-4">Cost Breakdown</h4>
            <table class="table">
                <tr><td>Direct Project Costs</td><td class="text-right">{{number_format($summary['direct'],2)}}</td></tr>
                <tr><td>Net Material Consumption</td><td class="text-right">{{number_format($summary['materials'],2)}}</td></tr>
                <tr><td>Project Wages</td><td class="text-right">{{number_format($summary['wages'],2)}}</td></tr>
                <tr><td>Approved Employee Rewards</td><td class="text-right">{{number_format($summary['rewards'],2)}}</td></tr>
                <tr><td>Employee Project Expenses</td><td class="text-right">{{number_format($summary['employeeExpenses'],2)}}</td></tr>
                <tr><td>Employee Advance Expenses Settled</td><td class="text-right">{{number_format($summary['advanceSettledExpenses'],2)}}</td></tr>
                <tr><td>Subcontractor Paid Cost</td><td class="text-right">{{number_format($summary['subcontractPaid'],2)}}</td></tr>
                <tr><td>Equipment Assignment Cost</td><td class="text-right">{{number_format($summary['equipment'],2)}}</td></tr>
                <tr><td>Transport Cost</td><td class="text-right">{{number_format($summary['transport'],2)}}</td></tr>
                <tr class="font-weight-bold"><td>Total Actual Cost</td><td class="text-right">{{number_format($summary['total'],2)}}</td></tr>
                <tr><td>Unpaid Subcontract Commitments (not included in actual cost)</td><td class="text-right">{{number_format(max(0,$summary['subcontractCommitted']-$summary['subcontractPaid']),2)}}</td></tr>
                <tr><td>Outstanding Employee Advances (asset/custody, not project cost until settled)</td><td class="text-right">{{number_format($summary['employeeAdvanceOutstanding'],2)}}</td></tr>
            </table>
            <div class="alert alert-info mb-0">
                Client receipts are treated as advances/customer-deposit liabilities until a future project billing or progress-certificate workflow recognizes revenue and receivables. "Remaining Contract Amount" is an operational contract figure, not General Ledger A/R.
            </div>
        </div>
    @else
        <div class="alert alert-info">Create a project to view its statement.</div>
    @endif
</div>
@endsection
