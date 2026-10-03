@extends('backend.layout.main')

@section('content')
    <x-success-message key="message" />
    <x-error-message key="not_permitted" />

    <section>
        <!-- Header -->
        <div class="container-fluid mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h3 class="font-weight-bold text-primary mb-1">
                        <i class="ti ti-calculator"></i> {{ __('db.Landed Costs & Allocation') }} — {{ $batch->batch_number }}
                    </h3>
                    <p class="text-muted small mb-0">
                        {{ $batch->title }} | {{ $batch->warehouse?->name }} | {{ __('db.Base Currency') }}: <strong>{{ $batch->baseCurrency?->code }}</strong>
                    </p>
                </div>
                <div class="mt-2 mt-md-0 d-flex align-items-center flex-wrap gap-2">
                    <a href="{{ route('import-batches.profitability', $batch->id) }}" class="btn btn-outline-info mr-2">
                        <i class="ti ti-chart-pie"></i> {{ __('db.View Profitability') }}
                    </a>
                    @if(!$batch->is_locked && $batch->status === 'finalized')
                        <form method="POST" action="{{ route('import-batches.reopen', $batch->id) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning mr-2" data-confirm="{{ __('db.Reopen this batch to draft? Cost layers will be reset until re-finalized.') }}" data-confirm-type="warning">
                                <i class="ti ti-arrow-back-up"></i> {{ __('db.Reopen to Draft') }}
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('import-batches.index') }}" class="btn btn-outline-secondary">
                        <i class="ti ti-arrow-left"></i> {{ __('db.Back to List') }}
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid">
            @if($batch->is_locked)
                <div class="alert alert-warning mb-3">
                    <i class="ti ti-lock"></i> <strong>{{ __('db.Costing Locked') }}:</strong>
                    {{ __('db.Stock from this import batch has already been consumed, transferred, or sold. Cost lines and allocations cannot be modified.') }}
                </div>
            @endif

            <!-- Summary KPI Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-2">
                    <div class="card shadow-sm border-0 border-left border-primary p-3">
                        <div class="text-muted small text-uppercase font-weight-bold">{{ __('db.Goods Purchase Cost') }}</div>
                        <div class="h4 font-weight-bold text-dark mb-0">
                            {{ number_format((float)$batch->total_goods_cost, 2) }}
                            <span class="small text-muted">{{ $batch->baseCurrency?->code }}</span>
                        </div>
                        <div class="text-muted small mt-1">{{ $batch->purchases->count() }} {{ __('db.purchases linked') }}</div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="card shadow-sm border-0 border-left border-warning p-3">
                        <div class="text-muted small text-uppercase font-weight-bold">{{ __('db.Total Landed Cost') }}</div>
                        <div class="h4 font-weight-bold text-warning mb-0">
                            +{{ number_format((float)$batch->total_landed_cost, 2) }}
                            <span class="small text-muted">{{ $batch->baseCurrency?->code }}</span>
                        </div>
                        <div class="text-muted small mt-1">{{ $batch->costs->count() }} {{ __('db.cost line items') }}</div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="card shadow-sm border-0 border-left border-success p-3">
                        <div class="text-muted small text-uppercase font-weight-bold">{{ __('db.Combined Landed Valuation') }}</div>
                        <div class="h4 font-weight-bold text-success mb-0">
                            {{ number_format((float)$batch->total_cost, 2) }}
                            <span class="small text-muted">{{ $batch->baseCurrency?->code }}</span>
                        </div>
                        <div class="text-muted small mt-1">
                            @if((float)$batch->total_goods_cost > 0)
                                +{{ number_format(((float)$batch->total_landed_cost / (float)$batch->total_goods_cost) * 100, 1) }}% {{ __('db.landed overhead') }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="card shadow-sm border-0 border-left border-info p-3">
                        <div class="text-muted small text-uppercase font-weight-bold">{{ __('db.Batch Status') }}</div>
                        <div class="h4 font-weight-bold mb-0">
                            @if($batch->status === 'finalized')
                                <span class="text-success">{{ __('db.Finalized') }}</span>
                            @else
                                <span class="text-warning">{{ __('db.Draft Costing') }}</span>
                            @endif
                        </div>
                        <div class="text-muted small mt-1">
                            {{ __('db.Method') }}: <span class="text-capitalize">{{ str_replace('_', ' ', $batch->allocation_method) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 1: Landed Cost Line Items -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="ti ti-receipt-2"></i> {{ __('db.Landed Cost Line Items') }}
                    </h5>
                    @if(!$batch->is_locked)
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addCostModal">
                            <i class="ti ti-plus"></i> {{ __('db.Add Cost Line') }}
                        </button>
                    @endif
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>{{ __('db.Cost Type') }}</th>
                                    <th>{{ __('db.Vendor / Supplier') }}</th>
                                    <th>{{ __('db.Reference / Inv #') }}</th>
                                    <th>{{ __('db.Original Amount') }}</th>
                                    <th>{{ __('db.FX Rate Snapshot') }}</th>
                                    <th>{{ __('db.Base Amount') }} ({{ $batch->baseCurrency?->code }})</th>
                                    <th>{{ __('db.Notes') }}</th>
                                    <th class="text-right not-exported">{{ __('db.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($batch->costs as $cost)
                                    <tr>
                                        <td>
                                            <span class="font-weight-bold text-dark">{{ $cost->cost_type }}</span>
                                        </td>
                                        <td>{{ $cost->vendor ? $cost->vendor->name : '-' }}</td>
                                        <td>{{ $cost->reference_no ?: '-' }}</td>
                                        <td>
                                            <span class="font-weight-medium">
                                                {{ number_format((float)$cost->original_amount, 2) }}
                                            </span>
                                            <span class="text-muted small">{{ $cost->currency?->code }}</span>
                                        </td>
                                        <td>
                                            <span class="badge badge-light border">
                                                1 {{ $batch->baseCurrency?->code }} = {{ rtrim(rtrim(number_format((float)$cost->exchange_rate, 6), '0'), '.') }} {{ $cost->currency?->code }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="font-weight-bold text-primary">
                                                {{ number_format((float)$cost->base_amount, 2) }}
                                            </span>
                                        </td>
                                        <td>{{ $cost->notes ?: '-' }}</td>
                                        <td class="text-right">
                                            @if(!$batch->is_locked)
                                                <div class="btn-group">
                                                    <form method="POST" action="{{ route('import-batches.costs.delete', $cost->id) }}" style="display:inline;">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-link text-danger p-0 ml-2" title="{{ __('db.delete') }}" data-confirm="{{ __('db.Are you sure want to delete this cost line?') }}" data-confirm-type="danger">
                                                            <i class="ti ti-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            @else
                                                <span class="text-muted small"><i class="ti ti-lock"></i></span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            <i class="ti ti-cash-off mb-1" style="font-size: 1.5rem;"></i>
                                            <div>{{ __('db.No landed cost lines recorded yet.') }}</div>
                                            @if(!$batch->is_locked)
                                                <button type="button" class="btn btn-sm btn-outline-primary mt-2" data-toggle="modal" data-target="#addCostModal">
                                                    <i class="ti ti-plus"></i> {{ __('db.Add Freight, Customs, or Other Charges') }}
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($batch->costs->isNotEmpty())
                                <tfoot class="bg-light font-weight-bold">
                                    <tr>
                                        <td colspan="5" class="text-right">{{ __('db.Total Allocated Landed Cost') }}:</td>
                                        <td class="text-warning font-weight-bold">
                                            {{ number_format((float)$batch->total_landed_cost, 2) }} {{ $batch->baseCurrency?->code }}
                                        </td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section 2: Product Allocation Breakdown & Finalization -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h5 class="font-weight-bold text-dark mb-0">
                            <i class="ti ti-table"></i> {{ __('db.Landed Cost Allocation Preview') }}
                        </h5>
                        <small class="text-muted">
                            {{ __('db.Allocated across purchase lines with exact penny rounding reconciliation.') }}
                        </small>
                    </div>

                    @if(!$batch->is_locked)
                        <form method="POST" action="{{ route('import-batches.finalize', $batch->id) }}" id="finalize-form">
                            @csrf
                            <input type="hidden" name="allocation_method" id="finalize_allocation_method" value="{{ $batch->allocation_method }}">
                            <button type="submit" class="btn btn-success" {{ $batch->purchases->isEmpty() ? 'disabled' : '' }}>
                                <i class="ti ti-check"></i> {{ __('db.Finalize & Generate Stock Layers') }}
                            </button>
                        </form>
                    @endif
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>{{ __('db.Purchase Ref') }}</th>
                                    <th>{{ __('db.Product') }}</th>
                                    <th class="text-center">{{ __('db.Received Qty') }}</th>
                                    <th class="text-right">{{ __('db.Base Goods Unit Cost') }}</th>
                                    <th class="text-right">{{ __('db.Base Goods Total') }}</th>
                                    <th class="text-right">{{ __('db.Allocated Landed Cost') }}</th>
                                    <th class="text-right text-primary font-weight-bold">{{ __('db.Final Landed Unit Cost') }}</th>
                                    <th class="text-right text-success font-weight-bold">{{ __('db.Total Combined Cost') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $sumGoods = 0;
                                    $sumLanded = 0;
                                    $sumTotal = 0;
                                @endphp
                                @forelse($allocations as $item)
                                    @php
                                        $goodsTotal = $item['qty'] * $item['base_unit_purchase_cost'];
                                        $combinedTotal = $item['qty'] * $item['total_unit_cost'];
                                        $sumGoods += $goodsTotal;
                                        $sumLanded += $item['allocated_landed_cost'];
                                        $sumTotal += $combinedTotal;
                                    @endphp
                                    <tr>
                                        <td><span class="font-weight-bold">{{ $item['purchase_ref'] }}</span></td>
                                        <td>
                                            <div class="font-weight-medium">{{ $item['product_name'] }}</div>
                                            <div class="text-muted small">{{ $item['product_code'] }}</div>
                                        </td>
                                        <td class="text-center">{{ number_format($item['qty'], 2) }}</td>
                                        <td class="text-right">{{ number_format($item['base_unit_purchase_cost'], 2) }}</td>
                                        <td class="text-right">{{ number_format($goodsTotal, 2) }}</td>
                                        <td class="text-right text-warning font-weight-medium">
                                            +{{ number_format($item['allocated_landed_cost'], 2) }}
                                            <div class="text-muted small">(+{{ number_format($item['unit_landed_cost'], 2) }}/unit)</div>
                                        </td>
                                        <td class="text-right text-primary font-weight-bold">
                                            {{ number_format($item['total_unit_cost'], 2) }}
                                        </td>
                                        <td class="text-right text-success font-weight-bold">
                                            {{ number_format($combinedTotal, 2) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            {{ __('db.No linked purchases or items to allocate.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if(!empty($allocations))
                                <tfoot class="bg-light font-weight-bold">
                                    <tr>
                                        <td colspan="4" class="text-right">{{ __('db.Totals') }}:</td>
                                        <td class="text-right">{{ number_format($sumGoods, 2) }}</td>
                                        <td class="text-right text-warning font-weight-bold">+{{ number_format($sumLanded, 2) }}</td>
                                        <td class="text-right">-</td>
                                        <td class="text-right text-success font-weight-bold">{{ number_format($sumTotal, 2) }} {{ $batch->baseCurrency?->code }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Modal: Add Landed Cost Line -->
    @if(!$batch->is_locked)
        <div class="modal fade" id="addCostModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title font-weight-bold">
                            <i class="ti ti-plus"></i> {{ __('db.Add Landed Cost Line Item') }}
                        </h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <form method="POST" action="{{ route('import-batches.costs.add', $batch->id) }}" id="add-cost-form">
                        @csrf
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">{{ __('db.Cost Type') }} *</label>
                                    <select name="cost_type" class="form-control" required>
                                        <option value="Sea / Ocean Freight">{{ __('db.Sea / Ocean Freight') }}</option>
                                        <option value="Air Freight">{{ __('db.Air Freight') }}</option>
                                        <option value="Customs Duty & Tariff">{{ __('db.Customs Duty & Tariff') }}</option>
                                        <option value="Port & Terminal Handling">{{ __('db.Port & Terminal Handling') }}</option>
                                        <option value="LC / Bank Charges">{{ __('db.LC / Bank Charges') }}</option>
                                        <option value="Marine Insurance">{{ __('db.Marine Insurance') }}</option>
                                        <option value="Inland Haulage / Trucking">{{ __('db.Inland Haulage / Trucking') }}</option>
                                        <option value="Clearing & Forwarding">{{ __('db.Clearing & Forwarding') }}</option>
                                        <option value="Inspection & Quarantine">{{ __('db.Inspection & Quarantine') }}</option>
                                        <option value="Demurrage & Storage">{{ __('db.Demurrage & Storage') }}</option>
                                        <option value="Other Landed Cost">{{ __('db.Other Landed Cost') }}</option>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">{{ __('db.Service Vendor / Supplier') }}</label>
                                    <select name="vendor_id" class="form-control selectpicker" data-live-search="true">
                                        <option value="">-- {{ __('db.Select Vendor (Optional)') }} --</option>
                                        @foreach($suppliers as $vendor)
                                            <option value="{{ $vendor->id }}">{{ $vendor->name }} ({{ $vendor->company_name }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold">{{ __('db.Original Amount') }} *</label>
                                    <input type="number" step="0.0001" min="0.0001" name="original_amount" id="original_amount" class="form-control" placeholder="0.00" required>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold">{{ __('db.Transaction Currency') }} *</label>
                                    <select name="currency_id" id="currency_id" class="form-control" required>
                                        @foreach($currencies as $c)
                                            <option value="{{ $c->id }}" data-rate="{{ $c->exchange_rate }}" {{ (int)$c->id === (int)$batch->base_currency_id ? 'selected' : '' }}>
                                                {{ $c->code }} ({{ $c->name }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold">
                                        {{ __('db.FX Rate Snapshot') }} *
                                    </label>
                                    <input type="number" step="0.00000001" min="0.00000001" name="exchange_rate" id="exchange_rate" class="form-control" value="1.00000000" required>
                                    <small class="form-text text-muted">
                                          1 {{ $batch->baseCurrency?->code }} = X {{ __('Foreign Unit') }}
                                    </small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">{{ __('db.Calculated Base Amount') }} ({{ $batch->baseCurrency?->code }})</label>
                                    <input type="text" id="calculated_base_display" class="form-control bg-light font-weight-bold text-success" readonly value="0.00">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">{{ __('db.Reference / Bill / Invoice No') }}</label>
                                    <input type="text" name="reference_no" class="form-control" placeholder="e.g. INV-9042">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-2">
                                    <label class="font-weight-bold">{{ __('db.Notes') }}</label>
                                    <textarea name="notes" class="form-control" rows="2" placeholder="{{ __('db.Optional notes about this expense...') }}"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('db.Close') }}</button>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="ti ti-check"></i> {{ __('db.Save Landed Cost') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @push('scripts')
        <script type="text/javascript">
            $(document).ready(function() {
                var baseCurrencyId = {{ (int)$batch->base_currency_id }};

                function updateCalculatedBase() {
                    var amt = parseFloat($('#original_amount').val()) || 0;
                    var rate = parseFloat($('#exchange_rate').val()) || 1;
                      var baseAmt = (amt / rate).toFixed(2);
                    $('#calculated_base_display').val(baseAmt + ' {{ $batch->baseCurrency?->code }}');
                }

                $('#original_amount, #exchange_rate').on('input', function() {
                    updateCalculatedBase();
                });

                $('#currency_id').on('change', function() {
                    var selected = $(this).val();
                    if (parseInt(selected) === baseCurrencyId) {
                        $('#exchange_rate').val('1.00000000');
                    }
                    updateCalculatedBase();
                });
            });
        </script>
    @endpush
@endsection
