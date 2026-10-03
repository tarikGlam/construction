<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('db.India GST Operational Report') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #222; font-size: 10px; }
        h1 { margin-bottom: 2px; font-size: 18px; }
        h2 { margin-top: 22px; font-size: 14px; }
        .notice { color: #555; margin-bottom: 12px; }
        .filters, .summary { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .filters td, .summary td { border: 1px solid #bbb; padding: 4px; }
        .register { width: 100%; border-collapse: collapse; font-size: 8px; }
        .register th, .register td { border: 1px solid #aaa; padding: 3px; }
        .register th { background: #eee; }
        .number { text-align: right; }
        .totals { font-weight: bold; background: #f1f1f1; }
        .print-button { margin-bottom: 12px; padding: 7px 14px; }
        @media print { .print-button { display: none; } }
    </style>
</head>
<body>
@if(!empty($printMode))
    <button type="button" class="print-button" onclick="window.print()">{{ __('db.Print') }}</button>
@endif

<h1>{{ __('db.India GST Operational Report') }}</h1>
<div class="notice">{{ __('db.Operational GST register and reconciliation tool. Not a statutory GSTN filing.') }}</div>

<table class="filters">
    <tr>
        <td><strong>{{ __('db.From Date') }}:</strong> {{ $filters['starting_date'] }}</td>
        <td><strong>{{ __('db.To Date') }}:</strong> {{ $filters['ending_date'] }}</td>
        <td><strong>{{ __('db.Place of Supply State') }}:</strong> {{ $filters['state_code'] ?: __('db.All') }}</td>
    </tr>
</table>

<table class="summary">
    <tr><td>{{ __('db.Net Output GST') }}</td><td class="number">{{ number_format($report['summary']['net_output_tax'], 2) }}</td></tr>
    <tr><td>{{ __('db.Net Purchase ITC') }}</td><td class="number">{{ number_format($report['summary']['net_purchase_itc'], 2) }}</td></tr>
    <tr><td>{{ __('db.Expense Eligible ITC') }}</td><td class="number">{{ number_format($report['summary']['expense_eligible_itc'], 2) }}</td></tr>
    <tr><td>{{ __('db.RCM Liability') }}</td><td class="number">{{ number_format($report['summary']['rcm_liability'], 2) }}</td></tr>
    <tr><td>{{ __('db.RCM Eligible ITC') }}</td><td class="number">{{ number_format($report['summary']['rcm_eligible_itc'], 2) }}</td></tr>
    <tr><td>{{ __('db.Blocked / Ineligible ITC (informational)') }}</td><td class="number">{{ number_format($report['summary']['total_ineligible_itc'], 2) }}</td></tr>
    <tr><td>{{ __('db.Total Available ITC') }}</td><td class="number">{{ number_format($report['summary']['total_available_itc'], 2) }}</td></tr>
    <tr><td><strong>{{ __('db.Indicative Net GST Position') }}</strong></td><td class="number"><strong>{{ number_format($report['summary']['indicative_net_position'], 2) }}</strong></td></tr>
</table>

@foreach($sections as $section)
    <h2>{{ $section['title'] }} {{ __('db.Register') }}</h2>
    <table class="register">
        <thead><tr>@foreach($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($section['rows'] as $row)
            <tr>@foreach($row as $value)<td class="{{ is_numeric($value) ? 'number' : '' }}">{{ is_float($value) ? number_format($value, 2, '.', '') : $value }}</td>@endforeach</tr>
        @endforeach
        <tr class="totals">@foreach($section['totals'] as $value)<td class="{{ is_numeric($value) ? 'number' : '' }}">{{ is_float($value) ? number_format($value, 2, '.', '') : $value }}</td>@endforeach</tr>
        </tbody>
    </table>
@endforeach
</body>
</html>
