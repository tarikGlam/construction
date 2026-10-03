@extends('backend.layout.main')
@push('css')
    @include('backend.layout.partials.datatable_css')
@endpush
 @section('content')
<style type="text/css">
    .top-fields{margin-top:10px;position: relative;}
    .top-fields label {font-size:11px;font-weight:600;margin-left:10px;padding:0 3px;position:absolute;top:-8px;z-index:9;}
    .top-fields input{font-size:13px;height:45px}
    .dt-buttons{width: 100%}
</style>
    <section class="forms">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header mt-2">
                    <h3 class="text-center">{{ __('db.Payment Report') }}</h3>
                </div>

                <form action="{{ route('report.paymentByDate') }}" method="POST">
                    @csrf
                <div class="row mb-3 product-report-filter">
                    <div class="col-md-3 offset-md-2 mt-3">
                        <div class="form-group top-fields">
                            <label class="d-tc">{{ __('db.Choose Your Date') }}</label>
                            <div class="d-tc">
                                <div class="input-group">
                                    <input type="text" class="daterangepicker-field form-control"
                                        value="{{ $start_date }} To {{ $end_date }}" required />
                                    <input type="hidden" name="start_date" value="{{ $start_date }}">
                                    <input type="hidden" name="end_date" value="{{ $end_date }}">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 offset-md-2 mt-3">
                        <div class="form-group top-fields">
                            <label class="d-tc">{{ __('db.Payment Method') }}</label>
                            <select name="payment_method" class="form-control" style="width: 30%;">
                                <option value="">{{ __('db.All') }}</option>
                                @foreach($payment_methods ?? [] as $method)
                                    <option value="{{ $method }}" {{ ($payment_method ?? '') === $method ? 'selected' : '' }}>
                                        {{ $method }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                </form>
            </div>
        </div>
        <div id="table-container">
            @include('backend.report.partials.payment_table')
        </div>
    </section>
@endsection

@push('scripts')
    @include('backend.layout.partials.datatable_js')
    @php
        $paymentReportLoadingText = __('db.Loading');
        if (is_array($paymentReportLoadingText)) {
            $paymentReportLoadingText = 'Loading';
        }
    @endphp
    <script type="text/javascript">
        const paymentReportLoadingText = @json($paymentReportLoadingText);

        function formatPaymentReportExportBody(data, row, column, node) {
            if (!data) return "";
            var $node = $(node);
            var $partyInfo = $node.find('.party-info');
            if ($partyInfo.length) {
                var name = $partyInfo.find('.party-name').text().trim();
                var phone = $partyInfo.find('.party-phone').text().trim();
                var badge = $partyInfo.find('.badge').text().trim();
                var parts = [];
                if (name) parts.push(name);
                if (phone) parts.push(phone);
                if (badge) parts.push(badge);
                return parts.join(' | ');
            }
            if (typeof data === 'string' && (data.indexOf('&mdash;') !== -1 || data.trim() === '—')) {
                return '—';
            }
            return $('<div>').html(data).text().trim();
        }

        function initializePaymentTable() {
            $('#report-table').DataTable({
                "destroy": true,
                "order": [],
                'language': {
                    'lengthMenu': '_MENU_ {{ __('db.records per page') }}',
                    "info": '<small>{{ __('db.Showing') }} _START_ - _END_ (_TOTAL_)</small>',
                    "search": '{{ __('db.Search') }}',
                    'paginate': {
                        'previous': '<i class="ti ti-chevron-left"></i>',
                        'next': '<i class="ti ti-chevron-right"></i>'
                    }
                },
                'columnDefs': [{
                        "orderable": false,
                        'targets': 0
                    },
                    {
                        'render': function(data, type, row, meta) {
                            if (type === 'display') {
                                data =
                                    '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>';
                            }

                            return data;
                        },
                        'checkboxes': {
                            'selectRow': true,
                            'selectAllRender': '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>'
                        },
                        'targets': [0]
                    }
                ],
                'select': {
                    style: 'multi',
                    selector: 'td:first-child'
                },
                'lengthMenu': [
                    [10, 25, 50, -1],
                    [10, 25, 50, "All"]
                ],
                dom: '<"row"lfB>rtip',
                buttons: [{
                        extend: 'pdf',
                        text: '<i title="export to pdf" class="ti ti-file-type-pdf"></i>',
                        exportOptions: {
                            columns: ':visible:Not(.not-exported)',
                            rows: ':visible',
                            format: {
                                body: formatPaymentReportExportBody
                            }
                        },
                        action: function(e, dt, button, config) {
                            datatable_sum(dt, true);
                            $.fn.dataTable.ext.buttons.pdfHtml5.action.call(this, e, dt, button, config);
                            datatable_sum(dt, false);
                        },
                        footer: true
                    },
                    {
                        extend: 'excel',
                        text: '<i title="export to excel" class="ti ti-file-type-xls"></i>',
                        exportOptions: {
                            columns: ':visible:Not(.not-exported)',
                            rows: ':visible',
                            format: {
                                body: formatPaymentReportExportBody
                            }
                        },
                        action: function(e, dt, button, config) {
                            datatable_sum(dt, true);
                            $.fn.dataTable.ext.buttons.excelHtml5.action.call(this, e, dt, button, config);
                            datatable_sum(dt, false);
                        },
                        footer: true
                    },
                    {
                        extend: 'csv',
                        text: '<i title="export to csv" class="ti ti-file-type-csv"></i>',
                        exportOptions: {
                            columns: ':visible:Not(.not-exported)',
                            rows: ':visible',
                            format: {
                                body: formatPaymentReportExportBody
                            }
                        },
                        action: function(e, dt, button, config) {
                            datatable_sum(dt, true);
                            $.fn.dataTable.ext.buttons.csvHtml5.action.call(this, e, dt, button, config);
                            datatable_sum(dt, false);
                        },
                        footer: true
                    },
                    {
                        extend: 'print',
                        text: '<i title="print" class="ti ti-printer"></i>',
                        exportOptions: {
                            columns: ':visible:Not(.not-exported)',
                            rows: ':visible',
                            format: {
                                body: formatPaymentReportExportBody
                            }
                        },
                        action: function(e, dt, button, config) {
                            datatable_sum(dt, true);
                            $.fn.dataTable.ext.buttons.print.action.call(this, e, dt, button, config);
                            datatable_sum(dt, false);
                        },
                        footer: true
                    },
                    {
                        extend: 'colvis',
                        text: '<i title="column visibility" class="ti ti-eye"></i>',
                        columns: ':gt(0)'
                    }
                ],
                drawCallback: function() {
                    datatable_sum(this.api(), false);
                }
            });
        }
        
        initializePaymentTable();

        function reloadPaymentTable() {
            var formData = $('form').serialize();
            $.ajax({
                url: "{{ route('report.paymentByDate') }}",
                data: formData,
                method: 'POST',
                beforeSend: function () {
                    $('#table-container').html('<div class="text-center mt-4"><i class="ti ti-spin fa-spinner"></i> ' + paymentReportLoadingText + '...</div>');
                },
                success: function (response) {
                    $('#table-container').html(response);
                    initializePaymentTable();
                }
            });
        }

        function paymentReportAmount(value) {
            var parsed = Number(String(value || '').replace(/[^0-9.-]/g, ''));
            return Number.isFinite(parsed) ? parsed : 0;
        }

        function datatable_sum(dt_selector, selectedRowsForExport) {
            var values;
            if (selectedRowsForExport && dt_selector.rows('.selected').any()) {
                var rows = dt_selector.rows('.selected').indexes();
                values = dt_selector.cells(rows, 9).data();
            } else {
                values = dt_selector.column(9, { search: 'applied' }).data();
            }

            var total = 0;
            values.each(function(value) {
                total += paymentReportAmount(value);
            });
            $(dt_selector.column(9).footer()).html(total.toFixed({{ gen_setting()->decimal }}));
        }

        // payment method change
        $('select[name="payment_method"]').on('change', function () {
            reloadPaymentTable();
        });

        // date range change
        $('.daterangepicker-field').on('apply.daterangepicker', function(ev, picker) {
            $('input[name="start_date"]').val(picker.startDate.format('YYYY-MM-DD'));
            $('input[name="end_date"]').val(picker.endDate.format('YYYY-MM-DD'));
            reloadPaymentTable();
        });

    </script>
@endpush
