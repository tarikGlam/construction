@if(config('database.connections.saleprosaas_landlord'))
<dl class="billing-lines">
    <div class="billing-line"><dt>{{ __('Billing period') }}</dt><dd>{{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end->format('d M Y') }}</dd></div>
    <div class="billing-line"><dt>{{ __('Net profit from accounting') }}</dt><dd>{{ $invoice->currency }} {{ number_format($invoice->net_profit, 2) }}</dd></div>
    <div class="billing-line"><dt>{{ __('SaaS fees added back') }}</dt><dd>{{ $invoice->currency }} {{ number_format($invoice->fee_addback, 2) }}</dd></div>
    <div class="billing-line billing-line-total"><dt>{{ __('Profit subject to commission') }}</dt><dd>{{ $invoice->currency }} {{ number_format($invoice->billable_profit, 2) }}</dd></div>
    <div class="billing-line"><dt>{{ __('Commission rate') }}</dt><dd>{{ number_format($invoice->commission_rate, 2) }}%</dd></div>
    <div class="billing-line billing-line-total"><dt>{{ __('Commission amount') }}</dt><dd>{{ $invoice->currency }} {{ number_format($invoice->amount, 2) }}</dd></div>
</dl>
<div class="billing-formula">
    {{ __('How this is calculated') }}<br>
    <strong>{{ $invoice->currency }} {{ number_format($invoice->billable_profit, 2) }} × {{ number_format($invoice->commission_rate, 2) }}% = {{ $invoice->currency }} {{ number_format($invoice->amount, 2) }}</strong>
</div>
@if($invoice->calculation)
    <details class="billing-details">
        <summary>{{ __('View accounting breakdown') }}</summary>
        <dl class="billing-lines">
            <div class="billing-line"><dt>{{ __('Net revenue') }}</dt><dd>{{ $invoice->currency }} {{ number_format(data_get($invoice->calculation, 'pnl.net_revenue', 0), 2) }}</dd></div>
            <div class="billing-line"><dt>{{ __('Cost of goods sold') }}</dt><dd>{{ $invoice->currency }} {{ number_format(data_get($invoice->calculation, 'pnl.total_cost_of_goods_sold', 0), 2) }}</dd></div>
            <div class="billing-line"><dt>{{ __('Operating expenses') }}</dt><dd>{{ $invoice->currency }} {{ number_format(data_get($invoice->calculation, 'pnl.total_expenses', 0), 2) }}</dd></div>
        </dl>
    </details>
@endif
@endif
