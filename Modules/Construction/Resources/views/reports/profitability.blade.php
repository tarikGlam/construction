@extends('backend.layout.main')
@section('construction-title','Project Profitability')
@section('content')
<div class="container-fluid">
    @include('construction::partials.header')
    <div class="alert alert-light border">
        Actual cost basis: direct paid project costs + net material issues + recorded project wages/rewards + employee project expenses/advance-settled expenses + subcontractor paid amounts + equipment assignment costs + project transport records. Unpaid subcontract commitments are shown separately. Projected Contract Profit uses full contract value and is not the same as realized accounting profit.
    </div>
    <div class="construction-table table-responsive">
        <table class="table table-bordered">
            <thead><tr><th>Project</th><th>Contract</th><th>Budget</th><th>Receipts</th><th>Direct</th><th>Materials</th><th>Labour</th><th>Employee Expenses</th><th>Advance-settled Expenses</th><th>Subcontract Paid</th><th>Equipment</th><th>Transport</th><th>Total Actual Cost</th><th>Unpaid Commitments</th><th>Cash Position</th><th>Projected Contract Profit</th><th>Contract Margin</th></tr></thead>
            <tbody>
            @foreach($rows as $row)
                @php($p=$row['project']) @php($s=$row['summary'])
                <tr>
                    <td><strong>{{$p->project_code}}</strong><br>{{$p->title}}</td>
                    <td>{{number_format($s['contract'],2)}}</td>
                    <td>{{number_format($p->budget,2)}}</td>
                    <td>{{number_format($s['receipts'],2)}}</td>
                    <td>{{number_format($s['direct'],2)}}</td>
                    <td>{{number_format($s['materials'],2)}}</td>
                    <td>{{number_format($s['labour'],2)}}</td>
                    <td>{{number_format($s['employeeExpenses'],2)}}</td>
                    <td>{{number_format($s['advanceSettledExpenses'],2)}}</td>
                    <td>{{number_format($s['subcontractPaid'],2)}}</td>
                    <td>{{number_format($s['equipment'],2)}}</td>
                    <td>{{number_format($s['transport'],2)}}</td>
                    <td><strong>{{number_format($s['total'],2)}}</strong></td>
                    <td>{{number_format(max(0,$s['subcontractCommitted']-$s['subcontractPaid']),2)}}</td>
                    <td class="{{$s['cashPosition']<0?'text-danger':'text-success'}}">{{number_format($s['cashPosition'],2)}}</td>
                    <td class="{{$s['projectedProfit']<0?'text-danger':'text-success'}}">{{number_format($s['projectedProfit'],2)}}</td>
                    <td>{{number_format($s['contractMargin'],1)}}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
