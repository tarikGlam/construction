@extends('backend.layout.main')

@push('css')
    @include('backend.layout.partials.datatable_css')
@endpush

@section('content')
<x-success-message key="message" />
<x-error-message key="not_permitted" />

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<section>
    <div class="container-fluid">
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('cashRegister.index') }}" class="row align-items-end">
                    <!-- <div class="col-md-2">
                        <label>{{ __('db.Start Date') }}</label>
                        <input type="date" name="starting_date" class="form-control" value="{{ request('starting_date') }}">
                    </div>
                    <div class="col-md-2">
                        <label>{{ __('db.End Date') }}</label>
                        <input type="date" name="ending_date" class="form-control" value="{{ request('ending_date') }}">
                    </div> --> 
                    <div class="col-md-3">
                        <div class="form-group top-fields">
                            <label>{{__('db.date')}}</label>
                            <input type="text" class="daterangepicker-field form-control" value="{{request('starting_date')}} To {{request('ending_date')}}" required />
                            <input type="hidden" name="starting_date" value="{{request('starting_date')}}" />
                            <input type="hidden" name="ending_date" value="{{request('ending_date')}}" />
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label>{{ __('db.Warehouse') }}</label>
                        <select name="warehouse_id" class="selectpicker form-control" data-live-search="true">
                            <option value="">{{ __('db.All Warehouse') }}</option>
                            @foreach($lims_warehouse_list as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) request('warehouse_id') === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>{{ __('db.User') }}</label>
                        <select name="user_id" class="selectpicker form-control" data-live-search="true">
                            <option value="">{{ __('db.All') }}</option>
                            @foreach($lims_user_list as $user)
                                <option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label>{{ __('db.Variance Status') }}</label>
                        <select name="variance_status" class="form-control">
                            <option value="">{{ __('db.All') }}</option>
                            <option value="balanced" @selected(request('variance_status') === 'balanced')>{{ __('db.Balanced') }}</option>
                            <option value="with_variance" @selected(request('variance_status') === 'with_variance')>{{ __('db.With Variance') }}</option>
                        </select>
                    </div>
                    <div class="col-md-2"><button class="btn btn-primary btn-block">{{ __('db.submit') }}</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="cash-register-table" class="table">
            <thead>
                <tr>
                    <th class="not-exported"></th>
                    <th>{{ __('db.Register Session') }}</th>
                    <th>{{__('db.User')}}</th>
                    <th>{{__('db.Warehouse')}}</th>
                    <th>{{__('db.Opened at')}}</th>
                    <th>{{ __('db.Closed By') }}</th>
                    <th>{{__('db.Closed at')}}</th>
                    <th>{{ __('db.Expected Total') }}</th>
                    <th>{{ __('db.Counted Total') }}</th>
                    <th>{{ __('db.Variance') }}</th>
                    <th>{{__('db.status')}}</th>
                    <th class="not-exported">{{__('db.action')}}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lims_cash_register_all as $key=>$cash_register)
                    @php($reconciliation = $cash_register->reconciliation)
                <tr data-id="{{$cash_register->id}}">
                    <td>{{$key}}</td>
                        <td>#{{ $cash_register->id }}</td>
                        <td>{{ $cash_register->user?->name }}</td>
                        <td>{{ $cash_register->warehouse?->name }}</td>
                        <td>{{ optional($cash_register->created_at)->format(gen_setting()->date_format . ' H:i:s') }}</td>
                        <td>{{ $reconciliation?->closedBy?->name ?? $cash_register->closedBy?->name ?? __('db.Not recorded') }}</td>
                        <td>{{ $reconciliation?->closed_at?->format(gen_setting()->date_format . ' H:i:s') ?? $cash_register->closed_at?->format(gen_setting()->date_format . ' H:i:s') ?? ($cash_register->status ? 'N/A' : optional($cash_register->updated_at)->format(gen_setting()->date_format . ' H:i:s')) }}</td>
                        <td>{{ $reconciliation ? number_format($reconciliation->expected_total, config('decimal')) : ($cash_register->closing_balance !== null ? number_format($cash_register->closing_balance, config('decimal')) : '—') }}</td>
                        <td>{{ $reconciliation ? number_format($reconciliation->counted_total, config('decimal')) : ($cash_register->actual_cash !== null ? number_format($cash_register->actual_cash, config('decimal')) : '—') }}</td>
                        <td class="{{ $reconciliation && bccomp($reconciliation->variance_total, '0', 4) !== 0 ? 'text-danger font-weight-bold' : '' }}">{{ $reconciliation ? number_format($reconciliation->variance_total, config('decimal')) : '—' }}</td>
                        <td><span class="badge badge-{{ $cash_register->status ? 'success' : 'danger' }}">{{ $cash_register->status ? __('db.Active') : __('db.Closed') }}</span></td>
                        <td><button type="button" data-id="{{ $cash_register->id }}" class="register-details-btn btn btn-sm btn-info" data-toggle="modal" data-target="#register-details-modal" title="{{ __('db.View') }}"><i class="ti ti-eye"></i></button></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div id="register-details-modal" tabindex="-1" role="dialog" aria-hidden="true" class="modal fade text-left">
        <div role="document" class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
                    <h5 class="modal-title">{{ __('db.Register Cash-Up') }}</h5>
              <button type="button" data-dismiss="modal" aria-label="Close" class="close"><span aria-hidden="true"><i class="ti ti-x"></i></span></button>
            </div>
            <div class="modal-body">
                    <div id="register-summary" class="small text-muted mb-3"></div>
                    <form id="cash-up-form" action="{{ route('cashRegister.close') }}" method="POST">
                            @csrf
                            <input type="hidden" name="cash_register_id">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>{{ __('db.Payment Method') }}</th><th>{{ __('db.System Expected') }}</th><th>{{ __('db.Operator Counted') }}</th><th>{{ __('db.Variance') }}</th><th>{{ __('db.Variance Reason') }}</th></tr></thead>
                                <tbody id="tender-rows"></tbody>
                                <tfoot><tr class="font-weight-bold"><td>{{ __('db.Total') }}</td><td id="expected-total">0.00</td><td id="counted-total">0.00</td><td id="variance-total">0.00</td><td></td></tr></tfoot>
                            </table>
                        </div>
                        <div class="form-group">
                            <label>{{ __('db.Closing Note') }}</label>
                            <textarea name="closing_note" class="form-control" rows="2" maxlength="2000"></textarea>
                    </div>
                        <div id="closing-section" class="text-right">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('db.Cancel') }}</button>
                            <button type="submit" class="btn btn-primary" data-confirm-message="{{ __('db.Close this register with the counted tender amounts?') }}" data-confirm-title="{{ __('db.Close Register') }}" data-confirm-type="warning">{{ __('db.Close Register') }}</button>
                </div>
                    </form>
            </div>
          </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
    @include('backend.layout.partials.datatable_js')
<script>
(function () {
    function amount(value) {
        var parsed = Number(value || 0);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatted(value) {
        return amount(value).toFixed({{ config('decimal') }});
        }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function updateTotals() {
        var expectedTotal = 0;
        var countedTotal = 0;
        $('#tender-rows tr').each(function () {
            var expected = amount($(this).data('expected'));
            var counted = amount($(this).find('.counted-amount').val());
            var variance = counted - expected;
            expectedTotal += expected;
            countedTotal += counted;
            $(this).find('.variance-value').text(formatted(variance)).toggleClass('text-danger font-weight-bold', Math.abs(variance) > 0.00005);
            $(this).find('.variance-reason').prop('required', Math.abs(variance) > 0.00005);
        });
        $('#expected-total').text(formatted(expectedTotal));
        $('#counted-total').text(formatted(countedTotal));
        $('#variance-total').text(formatted(countedTotal - expectedTotal)).toggleClass('text-danger', Math.abs(countedTotal - expectedTotal) > 0.00005);
    }

    $(document).on('input', '.counted-amount', updateTotals);

    $('.register-details-btn').on('click', function () {
        var id = $(this).data('id');
        $('#tender-rows').html('<tr><td colspan="5" class="text-center">{{ __('db.Loading...') }}</td></tr>');
        $.get('{{ url('cash-register/getDetails') }}/' + id).done(function (data) {
            $('#cash-up-form input[name=cash_register_id]').val(data.id);
            $('#cash-up-form textarea[name=closing_note]').val(data.closing_note || '');
            $('#register-summary').text('#' + data.id + ' · ' + data.warehouse + ' · {{ __('db.Opened By') }}: ' + data.opened_by + ' · ' + data.opened_at);
            $('#closing-section').toggleClass('d-none', !data.status);
            var rows = '';
            $.each(data.tenders, function (index, tender) {
                var closed = !data.status;
                var counted = tender.counted_amount == null ? tender.expected_amount : tender.counted_amount;
                rows += '<tr data-expected="' + escapeHtml(tender.expected_amount) + '">' +
                    '<td>' + escapeHtml(tender.method_label) + '<input type="hidden" name="tenders[' + index + '][method_key]" value="' + escapeHtml(tender.method_key) + '"></td>' +
                    '<td class="expected-value">' + formatted(tender.expected_amount) + '</td>' +
                    '<td><input type="number" step="0.0001" class="form-control form-control-sm counted-amount" name="tenders[' + index + '][counted_amount]" value="' + escapeHtml(counted) + '" ' + (closed ? 'readonly' : 'required') + '></td>' +
                    '<td class="variance-value">' + formatted(tender.variance_amount) + '</td>' +
                    '<td><input type="text" maxlength="1000" class="form-control form-control-sm variance-reason" name="tenders[' + index + '][variance_reason]" value="' + escapeHtml(tender.variance_reason) + '" ' + (closed ? 'readonly' : '') + '></td></tr>';
                        });
            if (data.legacy_closed) {
                rows = '<tr><td colspan="5" class="text-muted text-center">{{ __('db.Tender snapshot is not available for this legacy closed register.') }}</td></tr>';
                    }
            $('#tender-rows').html(rows);
            $('#cash-up-form textarea[name=closing_note]').prop('readonly', !data.status);
            if (data.legacy_closed) {
                $('#expected-total').text(data.expected_total == null ? '—' : formatted(data.expected_total));
                $('#counted-total').text(data.counted_total == null ? '—' : formatted(data.counted_total));
                $('#variance-total').text(data.variance_total == null ? '—' : formatted(data.variance_total));
            } else {
                updateTotals();
            }
        }).fail(function () {
            $('#tender-rows').html('<tr><td colspan="5" class="text-danger text-center">{{ __('db.We could not complete this action. Please try again.') }}</td></tr>');
        });
    });

    $('#cash-up-form').on('submit', function () {
        $(this).find('button[type=submit]').prop('disabled', true).text('{{ __('db.Closing...') }}');
    });

    $('#cash-register-table').DataTable({
        order: [],
        columnDefs: [{ orderable: false, targets: [0, 11] }],
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'All']],
        dom: '<"row"lfB>rtip',
        buttons: [
            { extend: 'pdf', exportOptions: { columns: ':visible:Not(.not-exported)' } },
            { extend: 'excel', exportOptions: { columns: ':visible:Not(.not-exported)' } },
            { extend: 'csv', exportOptions: { columns: ':visible:Not(.not-exported)' } },
            { extend: 'print', exportOptions: { columns: ':visible:Not(.not-exported)' } },
            { extend: 'colvis', columns: ':gt(0)' }
        ]
    } );
})();
</script>
@endpush
