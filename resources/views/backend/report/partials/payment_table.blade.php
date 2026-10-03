<div class="table-responsive mb-4" id="report-table-container">
    <table id="report-table" class="table table-hover">
        <thead>
            <tr>
                <th class="not-exported"></th>
                <th>{{ __('db.date') }}</th>
                <th>{{ __('db.Transaction Type') }}</th>
                <th>{{ __('db.Payment Reference') }} </th>
                <th>{{ __('db.Sale Reference') }}</th>
                <th>{{ __('db.Purchase Reference') }}</th>
                <th>{{ __('db.Party') }}</th>
                <th>{{ __('db.Payment Method') }}</th>
                <th>{{ __('db.Pay From Account') }}</th>
                <th>{{ __('db.Amount') }}</th>
                <th>{{ __('db.Created By') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lims_payment_data as $payment)
                @php
                    $party = $payment->party_details;
                    $saleRef = $payment->sale?->reference_no;
                    $purchaseRef = $payment->purchase?->reference_no;
                    $user = $payment->user;
                @endphp
                <tr>
                    <td></td>
                    <td>{{ date(gen_setting()->date_format, strtotime($payment->created_at->toDateString())) . ' ' . $payment->created_at->toTimeString() }}
                    </td>
                    <td>{{ __('db.' . $payment->report_transaction_type) }}</td>
                    <td>{{ $payment->payment_reference }}</td>
                    <td>{{ $saleRef ?? '' }}</td>
                    <td>{{ $purchaseRef ?? '' }}</td>
                    <td>
                        @if (!empty($party['name']))
                            <div class="party-info">
                                <div class="party-name font-weight-bold">{{ $party['name'] }}</div>
                                @if (!empty($party['phone']))
                                    <div class="party-phone text-muted small">{{ $party['phone'] }}</div>
                                @endif
                                <span class="badge {{ $party['type'] === 'Supplier' ? 'badge-info' : 'badge-primary' }}">
                                    {{ $party['type'] === 'Supplier' ? __('db.Supplier') : __('db.Customer') }}
                                </span>
                            </div>
                        @else
                            <span>&mdash;</span>
                        @endif
                    </td>
                    <td>{{ $payment->paying_method }}</td>
                    <td>
                        @if($payment->account)
                            {{ $payment->account->name }}@if($payment->account->account_no) ({{ $payment->account->account_no }})@endif
                        @else
                            <span>&mdash;</span>
                        @endif
                    </td>
                    <td>{{ number_format((float) $payment->amount, gen_setting()->decimal, '.', '') }}</td>
                    <td>
                        @if ($user)
                            {{ $user->name }}<br>{{ $user->email }}
                        @else
                            <span>&mdash;</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th></th>
                <th></th>
                <th></th>
                <th></th>
                <th></th>
                <th></th>
                <th></th>
                <th></th>
                <th>{{ __('db.Total') }}</th>
                <th>{{ number_format((float) $lims_payment_data->sum('amount'), gen_setting()->decimal, '.', '') }}</th>
                <th></th>
            </tr>
        </tfoot>
    </table>
</div>
