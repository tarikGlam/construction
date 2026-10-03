@extends('backend.layout.main')
@section('construction-title','Subcontractors & Contracts')
@section('content')
<div class="container-fluid">
    @include('construction::partials.header')
    @include('construction::partials.messages')

    <div class="row">
        <div class="col-lg-4">
            <form class="construction-form" method="post" action="{{route('construction.subcontractors.store')}}">
                @csrf
                <h4>Add Subcontractor</h4>
                <div class="form-group"><label>Name</label><input name="name" value="{{old('name')}}" class="form-control" required></div>
                <div class="form-group"><label>Company</label><input name="company_name" value="{{old('company_name')}}" class="form-control"></div>
                <div class="row">
                    <div class="col form-group"><label>Phone</label><input name="phone" value="{{old('phone')}}" class="form-control"></div>
                    <div class="col form-group"><label>Email</label><input name="email" value="{{old('email')}}" type="email" class="form-control"></div>
                </div>
                <input type="hidden" name="status" value="active">
                <button class="btn btn-primary">Add Subcontractor</button>
            </form>
        </div>

        <div class="col-lg-8">
            <form class="construction-form" method="post" enctype="multipart/form-data" action="{{route('construction.contracts.store')}}">
                @csrf
                <h4>Add Contract</h4>
                <div class="row">
                    <div class="col-md-4 form-group"><label>Contract No</label><input name="contract_no" value="{{old('contract_no')}}" class="form-control" required></div>
                    <div class="col-md-4 form-group"><label>Project</label><select name="project_id" class="form-control">@foreach($projects as $p)<option value="{{$p->id}}" @selected(old('project_id')==$p->id)>{{$p->title}}</option>@endforeach</select></div>
                    <div class="col-md-4 form-group"><label>Subcontractor</label><select name="subcontractor_id" class="form-control">@foreach($subcontractors as $s)<option value="{{$s->id}}" @selected(old('subcontractor_id')==$s->id)>{{$s->name}}</option>@endforeach</select></div>
                </div>
                <div class="form-group"><label>Scope</label><textarea name="scope" class="form-control" required>{{old('scope')}}</textarea></div>
                <div class="row">
                    <div class="col-md-4 form-group"><label>Contract Value</label><input type="number" step=".0001" min="0" name="contract_value" value="{{old('contract_value')}}" class="form-control" required></div>
                    <div class="col-md-4 form-group"><label>Start Date</label><input type="date" name="start_date" value="{{old('start_date')}}" class="form-control"></div>
                    <div class="col-md-4 form-group"><label>End Date</label><input type="date" name="end_date" value="{{old('end_date')}}" class="form-control"></div>
                    <div class="col-md-4 form-group"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="draft">Draft</option><option value="completed">Completed</option></select></div>
                    <div class="col-md-5 form-group"><label>Notes</label><input name="notes" value="{{old('notes')}}" class="form-control"></div><div class="col-md-3 form-group"><label>Contract Document</label><input type="file" name="attachment" class="form-control-file"></div>
                </div>
                <button class="btn btn-primary">Add Contract</button>
            </form>
        </div>
    </div>

    <div class="construction-table table-responsive mb-4">
        <h4>Contracts</h4>
        <table class="table">
            <thead><tr><th>Contract</th><th>Subcontractor</th><th>Project</th><th>Scope</th><th>Value</th><th>Paid</th><th>Outstanding</th><th>Document</th><th>Status</th></tr></thead>
            <tbody>@foreach($contracts as $c)<tr><td>{{$c->contract_no}}</td><td>{{$c->subcontractor->name}}</td><td>{{$c->project->title}}</td><td>{{$c->scope}}</td><td>{{number_format($c->contract_value,2)}}</td><td>{{number_format($c->paid_amount,2)}}</td><td>{{number_format($c->outstanding_amount,2)}}</td><td>@if($c->attachment)<a target="_blank" href="{{Storage::disk('public')->url($c->attachment)}}">View</a>@else — @endif</td><td>{{ucfirst($c->status)}}</td></tr>@endforeach</tbody>
        </table>
    </div>

    <div class="row">
        <div class="col-lg-5">
            <form class="construction-form" method="post" action="{{route('construction.subcontractor-payments.store')}}">
                @csrf
                <h4>Record Subcontractor Payment</h4>
                <div class="form-group"><label>Contract</label><select name="subcontractor_contract_id" class="form-control" required><option value="">Select contract</option>@foreach($contracts->where('status','!=','cancelled') as $c)<option value="{{$c->id}}" @selected(old('subcontractor_contract_id')==$c->id)>{{$c->contract_no}} · {{$c->subcontractor->name}} · Outstanding {{number_format($c->outstanding_amount,2)}}</option>@endforeach</select></div>
                <div class="row">
                    <div class="col-md-6 form-group"><label>Date</label><input type="date" name="payment_date" value="{{old('payment_date',date('Y-m-d'))}}" class="form-control" required></div>
                    <div class="col-md-6 form-group"><label>Amount</label><input type="number" step=".0001" min=".0001" name="amount" value="{{old('amount')}}" class="form-control" required></div>
                    <div class="col-md-6 form-group"><label>Store / Site Store</label><select name="warehouse_id" class="form-control" required>@foreach($warehouses as $w)<option value="{{$w->id}}" @selected(old('warehouse_id')==$w->id)>{{$w->name}}</option>@endforeach</select></div>
                    <div class="col-md-6 form-group"><label>Paid From</label><select name="account_id" class="form-control" required><option value="">Cash / Bank</option>@foreach($accounts as $a)<option value="{{$a->id}}" @selected(old('account_id')==$a->id)>{{$a->name}}</option>@endforeach</select></div>
                </div>
                <div class="form-group"><label>Reference</label><input name="reference" value="{{old('reference')}}" class="form-control"></div>
                <div class="form-group"><label>Notes</label><input name="notes" value="{{old('notes')}}" class="form-control"></div>
                <button class="btn btn-primary">Record Payment</button>
            </form>
        </div>

        <div class="col-lg-7">
            <div class="construction-table table-responsive">
                <h4>Recent Subcontractor Payments</h4>
                <table class="table">
                    <thead><tr><th>Date</th><th>Contract</th><th>Subcontractor</th><th>Project</th><th>Account</th><th>Status</th><th class="text-right">Amount</th></tr></thead>
                    <tbody>
                    @forelse($payments as $payment)
                        <tr>
                            <td>{{$payment->payment_date->format('Y-m-d')}}</td>
                            <td>{{$payment->contract->contract_no}}</td>
                            <td>{{$payment->subcontractor->name}}</td>
                            <td>{{$payment->project->title}}</td>
                            <td>{{optional($payment->account)->name ?: '—'}}</td>
                            <td><span class="badge {{$payment->posting_status==='posted'?'badge-success':'badge-warning'}}">{{ucfirst($payment->posting_status)}}</span></td>
                            <td class="text-right">{{number_format($payment->amount,2)}}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No payment history yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
