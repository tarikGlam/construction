@extends('backend.layout.main')
@section('content')
<div class="container-fluid">
@include('construction::partials.header')
@include('construction::partials.messages')

<div class="row mb-3">
    <div class="col-md-6"><div class="construction-card p-3"><small class="text-muted">Recorded Shareholder Capital</small><h3 class="mb-0">{{number_format($totals['capital'],2)}}</h3></div></div>
    <div class="col-md-6"><div class="construction-card p-3"><small class="text-muted">Outstanding Shareholder Loans</small><h3 class="mb-0">{{number_format($totals['loans'],2)}}</h3></div></div>
</div>

<div class="row">
<div class="col-lg-5">
<div class="construction-form mb-4">
<h4>Shareholder / Investor</h4>
<form method="post" action="{{route('construction.shareholders.store')}}">@csrf
<div class="form-row">
<div class="col-md-6 form-group"><label>Name</label><input name="name" class="form-control" required></div>
<div class="col-md-6 form-group"><label>Phone</label><input name="phone" class="form-control"></div>
<div class="col-md-6 form-group"><label>Email</label><input type="email" name="email" class="form-control"></div>
<div class="col-md-6 form-group"><label>Ownership %</label><input type="number" step=".0001" min="0" max="100" name="ownership_percentage" class="form-control"></div>
<div class="col-md-6 form-group"><label>Ownership Reference</label><input name="ownership_reference" class="form-control" placeholder="Share class / certificate / agreement"></div>
<div class="col-md-6 form-group"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
<div class="col-12 form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
<div class="col-12 form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
<div class="col-12"><button class="btn btn-primary">Save Shareholder</button></div>
</div>
</form>
</div>
</div>

<div class="col-lg-7">
<div class="construction-form mb-4">
<h4>Shareholder Deposit / Withdrawal / Financing</h4>
<p class="text-muted">Capital is posted to Shareholder Capital (equity). Shareholder financing/loans are posted to Shareholder Loans Payable. Cash/bank uses SalePro's mapped payment accounts.</p>
<form method="post" action="{{route('construction.shareholder-transactions.store')}}">@csrf
<div class="form-row">
<div class="col-md-6 form-group"><label>Shareholder</label><select name="shareholder_id" class="form-control" required>@foreach($shareholders->where('status','active') as $s)<option value="{{$s->id}}">{{$s->name}}</option>@endforeach</select></div>
<div class="col-md-6 form-group"><label>Transaction Type</label><select name="transaction_type" class="form-control" required><option value="capital_contribution">Capital Contribution / Deposit</option><option value="capital_withdrawal">Capital Withdrawal</option><option value="loan_received">Shareholder Financing / Loan Received</option><option value="loan_repayment">Shareholder Loan Repayment</option></select></div>
<div class="col-md-4 form-group"><label>Date</label><input type="date" name="transaction_date" value="{{date('Y-m-d')}}" class="form-control" required></div>
<div class="col-md-4 form-group"><label>Amount</label><input type="number" step=".0001" min=".0001" name="amount" class="form-control" required></div>
<div class="col-md-4 form-group"><label>Cash / Bank Account</label><select name="account_id" class="form-control" required>@foreach($accounts as $a)<option value="{{$a->id}}">{{$a->name}}</option>@endforeach</select></div>
<div class="col-md-6 form-group"><label>Reference</label><input name="reference" class="form-control"></div>
<div class="col-md-6 form-group"><label>Notes</label><input name="notes" class="form-control"></div>
<div class="col-12"><button class="btn btn-primary">Post Transaction</button></div>
</div>
</form>
</div>
</div>
</div>

<div class="construction-table table-responsive mb-4">
<h4>Shareholder Register</h4>
<table class="table"><thead><tr><th>Name</th><th>Contact</th><th>Ownership</th><th>Capital Balance</th><th>Loan Balance</th><th>Status</th><th>Notes</th></tr></thead><tbody>
@forelse($shareholders as $s)<tr><td><strong>{{$s->name}}</strong><br><small>{{$s->ownership_reference}}</small></td><td>{{$s->phone}}<br><small>{{$s->email}}</small></td><td>{{$s->ownership_percentage !== null ? number_format((float)$s->ownership_percentage,4).'%' : '—'}}</td><td>{{number_format($s->capital_balance,2)}}</td><td>{{number_format($s->loan_balance,2)}}</td><td>{{$s->status}}</td><td>{{$s->notes}}</td></tr>@empty<tr><td colspan="7">No shareholders recorded yet.</td></tr>@endforelse
</tbody></table>
</div>

<div class="construction-table table-responsive">
<h4>Related Financial Activity</h4>
<table class="table"><thead><tr><th>Date</th><th>Shareholder</th><th>Type</th><th>Amount</th><th>Cash / Bank</th><th>Reference</th><th>Journal</th><th>Status</th></tr></thead><tbody>
@forelse($transactions as $t)<tr><td>{{$t->transaction_date->format('Y-m-d')}}</td><td>{{$t->shareholder?->name}}</td><td>{{ucwords(str_replace('_',' ',$t->transaction_type))}}</td><td>{{number_format((float)$t->amount,2)}}</td><td>{{$t->account?->name}}</td><td>{{$t->reference}}</td><td>{{$t->journalEntry?->reference_no}}</td><td>{{$t->posting_status}}</td></tr>@empty<tr><td colspan="8">No shareholder financial activity yet.</td></tr>@endforelse
</tbody></table>
</div>
</div>
@endsection
