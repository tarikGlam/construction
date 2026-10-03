@extends('backend.layout.main')
@section('construction-title','Project Costs')
@section('content')
<div class="container-fluid">
    @include('construction::partials.header')
    @include('construction::partials.messages')

    <div class="alert alert-info">
        Direct Project Costs recorded here are paid costs. New records create a linked SalePro Expense and use the existing accounting engine. Unpaid supplier liabilities should be recorded through Procurement or Subcontractor Contracts instead of this form.
    </div>

    <form class="construction-form" method="post" enctype="multipart/form-data" action="{{ route('construction.costs.store') }}">
        @csrf
        <h4>Record Paid Project Cost</h4>
        <div class="row">
            <div class="col-md-3 form-group">
                <label>Project</label>
                <select name="project_id" class="form-control" required>
                    @foreach($projects as $p)<option value="{{$p->id}}" @selected(old('project_id')==$p->id)>{{$p->project_code}} {{$p->title}}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2 form-group">
                <label>Category</label>
                <select name="cost_category_id" class="form-control" required>
                    @foreach($categories as $c)<option value="{{$c->id}}" @selected(old('cost_category_id')==$c->id)>{{$c->name}}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2 form-group">
                <label>Supplier</label>
                <select name="supplier_id" class="form-control"><option value="">—</option>@foreach($suppliers as $s)<option value="{{$s->id}}" @selected(old('supplier_id')==$s->id)>{{$s->name}}</option>@endforeach</select>
            </div>
            <div class="col-md-2 form-group">
                <label>Store / Site Store</label>
                <select name="warehouse_id" class="form-control" required>@foreach($warehouses as $w)<option value="{{$w->id}}" @selected(old('warehouse_id')==$w->id)>{{$w->name}}</option>@endforeach</select>
            </div>
            <div class="col-md-3 form-group">
                <label>Paid From</label>
                <select name="account_id" class="form-control" required><option value="">Select Cash / Bank</option>@foreach($accounts as $a)<option value="{{$a->id}}" @selected(old('account_id')==$a->id)>{{$a->name}}</option>@endforeach</select>
            </div>
            <div class="col-md-2 form-group"><label>Date</label><input type="date" name="date" value="{{old('date',date('Y-m-d'))}}" class="form-control" required></div>
            <div class="col-md-2 form-group"><label>Amount</label><input type="number" step=".0001" min=".0001" name="amount" value="{{old('amount')}}" class="form-control" required></div>
            <div class="col-md-5 form-group"><label>Description</label><input name="description" value="{{old('description')}}" class="form-control" required></div>
            <div class="col-md-2 form-group"><label>Reference</label><input name="reference" value="{{old('reference')}}" class="form-control"></div><div class="col-md-3 form-group"><label>Attachment</label><input type="file" name="attachment" class="form-control-file"></div>
            <div class="col-md-1 form-group d-flex align-items-end"><button class="btn btn-primary">Save</button></div>
        </div>
    </form>

    <div class="construction-table table-responsive">
        <table class="table">
            <thead><tr><th>Date</th><th>Project</th><th>Category</th><th>Description</th><th>Reference</th><th>Attachment</th><th>Expense</th><th>Status</th><th class="text-right">Amount</th><th></th></tr></thead>
            <tbody>
            @foreach($costs as $cost)
                <tr>
                    <td>{{$cost->date->format('Y-m-d')}}</td>
                    <td>{{$cost->project->title}}</td>
                    <td>{{$cost->category->name}}</td>
                    <td>{{$cost->description}}</td>
                    <td>{{$cost->reference}}</td>
                    <td>@if($cost->attachment)<a target="_blank" href="{{Storage::disk('public')->url($cost->attachment)}}">View</a>@else — @endif</td>
                    <td>{{optional($cost->expense)->reference_no ?: '—'}}</td>
                    <td><span class="badge {{$cost->posting_status==='posted'?'badge-success':($cost->expense_id?'badge-warning':'badge-secondary')}}">{{ucfirst($cost->posting_status ?: 'operational')}}</span></td>
                    <td class="text-right">{{number_format($cost->amount,2)}}</td>
                    <td>
                        @if(!$cost->expense_id)
                            <form method="post" action="{{route('construction.costs.post',$cost)}}" class="form-inline flex-nowrap">
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
        {{$costs->links()}}
    </div>
</div>
@endsection
