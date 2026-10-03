@extends('backend.layout.main')
@section('construction-title','Construction ERP Settings')
@section('content')
<div class="container-fluid">
    @include('construction::partials.header')
    <div class="row">
        <div class="col-md-6">
            <div class="construction-table">
                <h4>Business Foundation</h4>
                <div class="list-group">
                    <a class="list-group-item list-group-item-action" href="{{route('setting.general')}}">Company, Currency & Tax</a>
                    <a class="list-group-item list-group-item-action" href="{{route('accounts.index')}}">Cash / Bank Accounts</a>
                    <a class="list-group-item list-group-item-action" href="{{route('accounting.chart-of-accounts.index')}}">Chart of Accounts</a>
                    <a class="list-group-item list-group-item-action" href="{{route('role.index')}}">Construction Users & Roles</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="construction-table">
                <h4>Construction Configuration</h4>
                <p>Construction ERP is the primary edition. Project, Procurement, Materials & Stores, Workforce, Equipment, Clients and Finance remain available.</p>
                <p><strong>Accounting policy:</strong> paid Project Costs create linked Expenses. Until project billing/progress certification is implemented, Client Project Receipts are posted as customer advances/deposit liabilities rather than earned revenue.</p>
                <p class="mb-0 text-muted">Unrelated commerce, optional vertical and regional add-ons are disabled in this edition.</p>
            </div>
        </div>
    </div>
</div>
@endsection
