<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Stock Ledger</title>
    <style>
        body{font-family:Arial,sans-serif;font-size:11px;color:#222}
        h2{margin-bottom:3px}.muted{color:#666}
        table{border-collapse:collapse;width:100%;margin-top:12px}
        th,td{border:1px solid #bbb;padding:5px;vertical-align:top}
        th{background:#eee}.num{text-align:right;white-space:nowrap}
        @media print{.no-print{display:none}}
    </style>
</head>
<body>
<button class="no-print" onclick="window.print()">Print</button>
<h2>Stock Ledger</h2>
<div class="muted">{{ $start_date }} to {{ $end_date }}</div>
<p>
    Opening Qty: {{ number_format($summary['opening_qty'] ?? 0, 4) }} &nbsp; | &nbsp;
    Total In: {{ number_format($summary['total_in'] ?? $summary['qty_in'] ?? 0, 4) }} &nbsp; | &nbsp;
    Total Out: {{ number_format($summary['total_out'] ?? $summary['qty_out'] ?? 0, 4) }} &nbsp; | &nbsp;
    Closing Qty: {{ number_format($summary['closing_qty'] ?? 0, 4) }} &nbsp; | &nbsp;
    Movements: {{ $summary['movement_count'] }}
</p>
<table>
    <thead>
        <tr>
            <th>Date/Time</th><th>Source</th><th>Reference</th><th>Warehouse</th><th>Product</th><th>Batch/IMEI</th><th>In</th><th>Out</th><th>Balance</th>
        </tr>
    </thead>
    <tbody>
    @forelse($movements as $row)
        <tr>
            <td>{{ $row['movement_at'] }}</td>
            <td>{{ ucwords(str_replace('_',' ',$row['source_type'])) }}<br><small>#{{ $row['source_id'] }}</small></td>
            <td>{{ $row['reference_no'] }}@if($row['note'])<br><small>{{ $row['note'] }}</small>@endif</td>
            <td>{{ $row['warehouse_name'] }}</td>
            <td>
                {{ $row['product_name'] }} [{{ $row['product_code'] }}]
                @if($row['variant_name']) · {{ $row['variant_name'] }}@endif
                @if($row['uom']) · {{ $row['uom'] }}@endif
                <br><small>{{ $row['category_name'] ?? '—' }} / {{ $row['brand_name'] ?? '—' }}</small>
            </td>
            <td>
                @if($row['batch_no'])Batch: {{ $row['batch_no'] }}@endif
                @if($row['imei_number'])<br>IMEI/Serial: {{ $row['imei_number'] }}@endif
            </td>
            <td class="num">{{ $row['qty_in'] ? number_format($row['qty_in'],4) : '' }}</td>
            <td class="num">{{ $row['qty_out'] ? number_format($row['qty_out'],4) : '' }}</td>
            <td class="num">{{ number_format($row['running_balance'],4) }}</td>
        </tr>
    @empty
        <tr><td colspan="9">No stock movements match the selected filters.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
