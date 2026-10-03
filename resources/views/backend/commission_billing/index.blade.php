@php
    abort_unless(config('database.connections.saleprosaas_landlord'), 404);
@endphp
@extends('backend.layout.main')
@push('css')
    @include('shared.commission-billing.styles')
@endpush
@section('content')
<section class="container-fluid commission-ui">
    <div class="billing-heading">
        <div><span class="billing-kicker">{{ __('Your subscription') }}</span><h1>{{ __('Subscription Billing') }}</h1><p class="billing-muted">{{ __('Review your monthly commission, check invoices, and report payments in one place.') }}</p></div>
        <span class="billing-badge">{{ __('Monthly profit plan') }}</span>
    </div>
    @include('shared.commission-billing.messages')
    <div class="billing-stats">
        <div class="billing-card billing-stat"><span class="billing-icon"><i class="ti ti-percentage" aria-hidden="true"></i></span><div><span class="billing-stat-label">{{ __('Your commission rate') }}</span><strong class="billing-stat-value">{{ number_format($profile->commission_rate, 2) }}%</strong><span class="billing-muted">{{ __('Of positive monthly profit · Zero upfront') }}</span></div></div>
        <div class="billing-card billing-stat"><span class="billing-icon"><i class="ti ti-wallet" aria-hidden="true"></i></span><div><span class="billing-stat-label">{{ __('Outstanding balance') }}</span><strong class="billing-stat-value"><small>{{ $profile->currency }}</small> {{ number_format($outstandingAmount, 2) }}</strong><span class="billing-muted">{{ $outstandingCount }} {{ __('approved invoices awaiting payment') }}</span></div></div>
        <div class="billing-card billing-stat"><span class="billing-icon"><i class="ti ti-calendar" aria-hidden="true"></i></span><div><span class="billing-stat-label">{{ __('Payment window') }}</span><strong class="billing-stat-value">{{ $profile->payment_due_days }} <small>{{ __('days') }}</small></strong><span class="billing-muted">{{ __('After approval') }} · {{ $profile->grace_days }} {{ __('extra grace days') }}</span></div></div>
    </div>

    <div class="billing-grid">
        <div>
            <div class="billing-section-title"><h2>{{ __('Your invoices') }}</h2><span class="billing-muted">{{ $invoices->total() }} {{ __('invoices') }}</span></div>
            <div class="billing-stack">
                @forelse($invoices as $invoice)
                    <article class="billing-card" id="invoice-{{ $invoice->id }}">
                        <div class="billing-card-body">
                            <div class="billing-invoice-top">
                                <div><h3>{{ __('Invoice') }} #{{ $invoice->id }}</h3><p class="billing-muted">{{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end->format('d M Y') }}</p></div>
                                <div>@include('shared.commission-billing.status')</div>
                            </div>
                            <div class="billing-heading mb-0">
                                <div><span class="billing-stat-label">{{ $invoice->status === 'paid' ? __('Amount paid') : __('Commission amount') }}</span><strong class="billing-invoice-amount">{{ $invoice->currency }} {{ number_format($invoice->amount, 2) }}</strong></div>
                                @if($invoice->due_on && $invoice->status === 'due')<div class="billing-muted">{{ __('Payment due') }}<br><strong>{{ $invoice->due_on->format('d M Y') }}</strong></div>@endif
                            </div>
                            <details class="billing-details" @if($loop->first) open @endif>
                                <summary>{{ __('View invoice calculation') }}</summary>
                                @include('shared.commission-billing.calculation')
                            </details>

                            @if(in_array($invoice->status, ['review', 'blocked']))
                                <div class="billing-note mt-4 mb-0"><i class="ti ti-info-circle" aria-hidden="true"></i><span>{{ __('This month is being reviewed. No payment is due until the invoice is approved.') }}</span></div>
                            @elseif($invoice->status === 'due')
                                <div class="billing-details">
                                    <h3>{{ $invoice->payment_claimed_at ? __('Your payment report') : __('Already made your payment?') }}</h3>
                                    @if($invoice->payment_claimed_at)
                                        <div class="billing-note billing-note-warning"><i class="ti ti-clock" aria-hidden="true"></i><span>{{ __('Payment reported; awaiting verification.') }}<br>{{ __('Submitted') }} {{ $invoice->payment_claimed_at->format('d M Y, H:i') }}</span></div>
                                    @else
                                        <p class="billing-muted mb-3">{{ __('Follow the payment instructions, then send your transaction details for verification.') }}</p>
                                    @endif
                                    <form method="POST" action="{{ route('subscription.billing.claim', $invoice->id) }}">
                                        @csrf
                                        <input type="hidden" name="_invoice_id" value="{{ $invoice->id }}">
                                        <div class="billing-field">
                                            <label for="claim-{{ $invoice->id }}">{{ __('Payment details') }}</label>
                                            <textarea id="claim-{{ $invoice->id }}" class="form-control" name="payment_claim" maxlength="2000" required aria-describedby="claim-help-{{ $invoice->id }}" placeholder="{{ __('Payment method, date, amount and transaction reference') }}">{{ (string) old('_invoice_id') === (string) $invoice->id ? old('payment_claim', $invoice->payment_claim) : $invoice->payment_claim }}</textarea>
                                            <span id="claim-help-{{ $invoice->id }}" class="billing-muted">{{ __('Include the method, payment date, full amount and transaction reference. The administrator will verify receipt.') }}</span>
                                        </div>
                                        <button class="btn btn-primary" type="submit"><i class="ti ti-send mr-1" aria-hidden="true"></i> {{ $invoice->payment_claimed_at ? __('Update payment details') : __('Send payment details for verification') }}</button>
                                    </form>
                                </div>
                            @elseif($invoice->status === 'paid')
                                <div class="billing-note billing-note-success mt-4 mb-0"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ __('Payment verified. Thank you!') }}<br>{{ $invoice->paid_at?->format('d M Y') }} · {{ $invoice->payment_method }} · {{ $invoice->payment_reference }}</span></div>
                            @elseif($invoice->status === 'no_charge')
                                <div class="billing-note billing-note-success mt-4 mb-0"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ __('No payment is required for this period.') }}</span></div>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="billing-card billing-empty"><span class="billing-icon"><i class="ti ti-file-invoice" aria-hidden="true"></i></span><h3>{{ __('No invoices yet') }}</h3><p class="billing-muted">{{ __('Your first invoice will be prepared after the calendar month ends.') }}</p></div>
                @endforelse
            </div>
            <div class="billing-pagination">{{ $invoices->links() }}</div>
        </div>

        <aside class="billing-stack" aria-label="{{ __('Payment instructions and subscription terms') }}">
            <article class="billing-card">
                <div class="billing-card-head"><div class="billing-title"><span class="billing-icon"><i class="ti ti-building-bank" aria-hidden="true"></i></span><h2>{{ __('How to pay') }}</h2></div></div>
                <div class="billing-card-body">
                    <p class="billing-muted mb-4">{{ __('Pay the approved invoice using the details below, then report your payment beside the invoice.') }}</p>
                    @forelse($manualDetails as $label => $value)
                        <dl class="billing-payment-detail"><dt>{{ __($label) }}</dt><dd>{{ $value }}</dd></dl>
                    @empty
                        <div class="billing-note mb-0"><i class="ti ti-info-circle" aria-hidden="true"></i><span>{{ __('Contact the platform administrator for payment instructions.') }}</span></div>
                    @endforelse
                </div>
            </article>
            <article class="billing-card">
                <div class="billing-card-head"><h2>{{ __('Your plan at a glance') }}</h2></div>
                <div class="billing-card-body">
                    <dl class="billing-lines">
                        <div class="billing-line"><dt>{{ __('Billing cycle') }}</dt><dd>{{ __('Calendar month') }}</dd></div>
                        <div class="billing-line"><dt>{{ __('Currency') }}</dt><dd>{{ $profile->currency }}</dd></div>
                        <div class="billing-line"><dt>{{ __('Timezone') }}</dt><dd>{{ $profile->timezone }}</dd></div>
                    </dl>
                    <ul class="billing-terms mt-3">
                        <li>{{ __('No upfront fee. No charge in loss-making or zero-profit months.') }}</li>
                        <li>{{ __('Your referral does not add any charge to your bill.') }}</li>
                        <li>{{ __('Pay approved invoices within') }} {{ $profile->payment_due_days }} {{ __('days. After a further') }} {{ $profile->grace_days }} {{ __('days, new transactions are restricted until payment is verified. You can still view records and contact support.') }}</li>
                    </ul>
                    <details class="billing-details"><summary>{{ __('Accounting note') }}</summary><p class="billing-muted">{{ __('Record subscription fees in the “SaaS profit commission” expense account so those fees are correctly added back to the profit calculation.') }}</p></details>
                </div>
            </article>
        </aside>
    </div>
</section>
@endsection
