@extends('backend.layout.main')
@section('construction-title','Client Project Receipts')
@section('content')
<div class="container-fluid">
    @include('construction::partials.header')
    @include('construction::partials.messages')

    <div class="alert alert-info">
        Until progress billing/certification is implemented, Project Receipts are accounted as <strong>Client Advances</strong>: Cash/Bank is debited and Customer Deposit Liability is credited. They are not treated as earned project revenue.
    </div>

    <form class="construction-form" method="post" action="{{route('construction.receipts.store')}}">
        @csrf
        <h4>Record Client Advance / Receipt</h4>
        <div class="row">
            <div class="col-md-3 form-group"><label>Project / Client</label><select name="project_id" class="form-control" required>@foreach($projects as $p)<option value="{{$p->id}}" @selected(old('project_id')==$p->id)>{{$p->title}} · {{optional($p->customer)->name}}</option>@endforeach</select></div>
            <div class="col-md-2 form-group"><label>Store / Site Store</label><select name="warehouse_id" class="form-control" required>@foreach($warehouses as $w)<option value="{{$w->id}}" @selected(old('warehouse_id')==$w->id)>{{$w->name}}</option>@endforeach</select></div>
            <div class="col-md-2 form-group"><label>Date</label><input type="date" name="receipt_date" value="{{old('receipt_date',date('Y-m-d'))}}" class="form-control" required></div>
            <div class="col-md-2 form-group"><label>Amount</label><input type="number" step=".0001" min=".0001" name="amount" value="{{old('amount')}}" class="form-control" required></div>
            <div class="col-md-3 form-group"><label>Cash / Bank</label><select name="account_id" class="form-control" required><option value="">Select account</option>@foreach($accounts as $a)<option value="{{$a->id}}" @selected(old('account_id')==$a->id)>{{$a->name}}</option>@endforeach</select></div>
            <div class="col-md-2 form-group"><label>Method</label><select name="payment_method" class="form-control"><option @selected(old('payment_method')==='Cash')>Cash</option><option @selected(old('payment_method')==='Bank Transfer')>Bank Transfer</option><option @selected(old('payment_method')==='Cheque')>Cheque</option></select></div>
            <div class="col-md-3 form-group"><label>External Reference</label><input name="external_reference" value="{{old('external_reference')}}" class="form-control"></div>
            <div class="col-md-5 form-group"><label>Notes</label><input name="notes" value="{{old('notes')}}" class="form-control"></div>
            <div class="col-md-2 form-group d-flex align-items-end"><button class="btn btn-primary">Record Receipt</button></div>
        </div>
    </form>

    <div class="construction-table table-responsive">
        <table class="table">
            <thead><tr><th>Date</th><th>Reference</th><th>Client</th><th>Project</th><th>Store</th><th>Account</th><th>Method</th><th>Accounting</th><th>Deposit</th><th class="text-right">Amount</th><th></th></tr></thead>
            <tbody>
            @foreach($receipts as $r)
                <tr>
                    <td>{{$r->receipt_date->format('Y-m-d')}}</td>
                    <td>{{$r->reference_no}}</td>
                    <td>{{$r->customer->name}}</td>
                    <td>{{$r->project->title}}</td>
                    <td>{{optional($r->warehouse)->name ?: '—'}}</td>
                    <td>{{optional($r->account)->name ?: '—'}}</td>
                    <td>{{$r->payment_method}}</td>
                    <td><span class="badge {{$r->posting_status==='posted'?'badge-success':($r->deposit_id?'badge-warning':'badge-secondary')}}">{{ucfirst($r->posting_status ?: 'operational')}}</span></td>
                    <td>{{$r->deposit_id ? 'DEP-'.$r->deposit_id : '—'}}</td>
                    <td class="text-right">{{number_format($r->amount,2)}}</td>
                    <td>
                        @if(!$r->deposit_id)
                            <form method="post" action="{{route('construction.receipts.post',$r)}}" class="form-inline flex-nowrap">
                                @csrf
                                <select name="warehouse_id" class="form-control form-control-sm mr-1" required><option value="">Store</option>@foreach($warehouses as $w)<option value="{{$w->id}}">{{$w->name}}</option>@endforeach</select>
                                <select name="account_id" class="form-control form-control-sm mr-1" required><option value="">Cash / Bank</option>@foreach($accounts as $a)<option value="{{$a->id}}">{{$a->name}}</option>@endforeach</select>
                                <button class="btn btn-sm btn-outline-primary">Post</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        {{$receipts->links()}}
    </div>
</div>
@endsection
