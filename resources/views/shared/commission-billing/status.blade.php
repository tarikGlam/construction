@if(config('database.connections.saleprosaas_landlord'))
@php
    $billingStatusLabels = [
        'review' => __('Awaiting review'), 'blocked' => __('Needs attention'),
        'due' => __('Awaiting payment'), 'paid' => __('Paid'), 'no_charge' => __('No charge'),
    ];
    $billingStatus = array_key_exists($invoice->status, $billingStatusLabels) ? $invoice->status : 'review';
@endphp
<span class="billing-badge billing-badge-{{ $billingStatus }}">{{ $billingStatusLabels[$billingStatus] }}</span>
@if($invoice->status === 'due' && $invoice->payment_claimed_at)
    <span class="billing-reported"><i class="ti ti-message-check" aria-hidden="true"></i> {{ __('Payment reported') }}</span>
@endif
@endif
