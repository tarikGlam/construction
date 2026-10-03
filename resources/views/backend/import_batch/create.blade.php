@extends('backend.layout.main')

@section('content')
    <x-success-message key="message" />
    <x-error-message key="not_permitted" />

    <section class="forms">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <h4 class="font-weight-bold text-primary mb-0">
                                <i class="ti ti-plus"></i> {{ __('db.Create Import / Landed Cost Batch') }}
                            </h4>
                            <a href="{{ route('import-batches.index') }}" class="btn btn-outline-secondary btn-sm">
                                <i class="ti ti-arrow-left"></i> {{ __('db.Back to List') }}
                            </a>
                        </div>
                        <div class="card-body">
                            <p class="italic text-muted mb-4">
                                {{ __('db.The field labels marked with * are required input fields.') }}
                                {{ __('db.Base currency is snapshotted as') }} <strong>{{ $baseCurrency?->name }} ({{ $baseCurrency?->code }})</strong>.
                            </p>

                            <form method="POST" action="{{ route('import-batches.store') }}" id="import-batch-form">
                                @csrf

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Receiving Warehouse') }} *</label>
                                        <select name="warehouse_id" id="warehouse_id" class="form-control selectpicker" data-live-search="true" required>
                                            @foreach($warehouses as $warehouse)
                                                <option value="{{ $warehouse->id }}" {{ old('warehouse_id', $defaultWarehouseId) == $warehouse->id ? 'selected' : '' }}>
                                                    {{ $warehouse->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="form-text text-muted">
                                            {{ __('db.Authoritative destination warehouse. All linked purchases must belong to this warehouse.') }}
                                        </small>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Container / B/L / Reference No') }}</label>
                                        <input type="text" name="reference_no" class="form-control" value="{{ old('reference_no') }}" placeholder="e.g. MSKU1234567 / BL-8821">
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Batch Title') }} *</label>
                                        <input type="text" name="title" class="form-control" value="{{ old('title', 'Container Import ' . date('Y-m')) }}" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Default Landed Cost Allocation Method') }} *</label>
                                        <select name="allocation_method" class="form-control" required>
                                            <option value="purchase_value" {{ old('allocation_method') == 'purchase_value' ? 'selected' : '' }}>
                                                {{ __('db.By Purchase Value (Pro-rata by Goods Cost)') }}
                                            </option>
                                            <option value="quantity" {{ old('allocation_method') == 'quantity' ? 'selected' : '' }}>
                                                {{ __('db.By Quantity (Pro-rata by Unit Count)') }}
                                            </option>
                                            <option value="manual" {{ old('allocation_method') == 'manual' ? 'selected' : '' }}>
                                                {{ __('db.Manual Allocation (Custom Weights)') }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Received Date') }}</label>
                                        <input type="date" name="received_at" class="form-control" value="{{ old('received_at', date('Y-m-d')) }}">
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Notes / Description') }}</label>
                                        <textarea name="notes" class="form-control" rows="1" placeholder="{{ __('db.Optional notes about shipment, port of entry, or customs broker...') }}">{{ old('notes') }}</textarea>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <div class="mb-4">
                                    <h5 class="font-weight-bold text-dark mb-2">
                                        <i class="ti ti-shopping-cart"></i> {{ __('db.Link Initial Purchases from this Warehouse') }}
                                    </h5>
                                    <p class="text-muted small">
                                        {{ __('db.Select purchases arriving in this container. Only purchases for the selected warehouse are displayed.') }}
                                    </p>

                                    <div id="purchases-loading" class="text-muted py-2" style="display:none;">
                                        <i class="ti ti-loader rotate"></i> {{ __('db.Loading purchases...') }}
                                    </div>

                                    <div class="table-responsive border rounded" style="max-height: 320px; overflow-y: auto;">
                                        <table class="table table-hover table-sm mb-0" id="purchases-selection-table">
                                            <thead class="bg-light sticky-top">
                                                <tr>
                                                    <th width="40" class="text-center">
                                                        <input type="checkbox" id="select-all-purchases">
                                                    </th>
                                                    <th>{{ __('db.Reference') }}</th>
                                                    <th>{{ __('db.Date') }}</th>
                                                    <th>{{ __('db.Supplier') }}</th>
                                                    <th>{{ __('db.Items') }}</th>
                                                    <th class="text-right">{{ __('db.Grand Total') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody id="purchases-tbody">
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-3">
                                                        {{ __('db.Select a warehouse to load eligible purchases.') }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <button type="submit" class="btn btn-primary px-4 py-2">
                                        <i class="ti ti-arrow-right"></i> {{ __('db.Save & Proceed to Landed Costs') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @push('scripts')
        <script type="text/javascript">
            $(document).ready(function() {
                function loadPurchases(warehouseId) {
                    if (!warehouseId) return;
                    $('#purchases-loading').show();
                    $('#purchases-tbody').html('<tr><td colspan="6" class="text-center text-muted py-3">{{ __("db.Loading purchases...") }}</td></tr>');

                    $.ajax({
                        url: "{{ url('import-batches/purchases-by-warehouse') }}/" + warehouseId,
                        type: 'GET',
                        dataType: 'json',
                        success: function(data) {
                            $('#purchases-loading').hide();
                            var html = '';
                            if (data.length === 0) {
                                html = '<tr><td colspan="6" class="text-center text-muted py-3">{{ __("db.No unlinked purchases found for this warehouse.") }}</td></tr>';
                            } else {
                                $.each(data, function(index, p) {
                                    var supplierName = p.supplier ? p.supplier.name : '-';
                                    var currCode = p.currency ? p.currency.code : '';
                                    var dateFormatted = p.created_at ? p.created_at.substring(0, 10) : '';

                                    html += '<tr>' +
                                        '<td class="text-center"><input type="checkbox" name="purchase_ids[]" value="' + p.id + '" class="purchase-checkbox"></td>' +
                                        '<td class="font-weight-bold">' + p.reference_no + '</td>' +
                                        '<td>' + dateFormatted + '</td>' +
                                        '<td>' + supplierName + '</td>' +
                                        '<td>' + p.item + '</td>' +
                                        '<td class="text-right font-weight-bold">' + parseFloat(p.grand_total).toFixed(2) + ' ' + currCode + '</td>' +
                                        '</tr>';
                                });
                            }
                            $('#purchases-tbody').html(html);
                        },
                        error: function() {
                            $('#purchases-loading').hide();
                            $('#purchases-tbody').html('<tr><td colspan="6" class="text-center text-danger py-3">{{ __("db.Failed to load purchases.") }}</td></tr>');
                        }
                    });
                }

                $('#warehouse_id').on('change', function() {
                    loadPurchases($(this).val());
                });

                $('#select-all-purchases').on('change', function() {
                    $('.purchase-checkbox').prop('checked', $(this).prop('checked'));
                });

                var initWarehouseId = $('#warehouse_id').val();
                if (initWarehouseId) {
                    loadPurchases(initWarehouseId);
                }
            });
        </script>
    @endpush
@endsection
