@extends('backend.layout.main')

@push('css')
<style>
    .top-fields { margin-top: 10px; position: relative; }
    .top-fields label { font-size: 11px; font-weight: 600; margin-left: 10px; padding: 0 3px; position: absolute; top: -8px; z-index: 9; }
    .top-fields input { font-size: 13px; height: 40px; }
    .stock-summary-card { min-height: 90px; }
    .stock-summary-card .summary-label { font-size: 12px; color: #6c757d; font-weight: 600; text-transform: uppercase; margin-bottom: 4px; }
    .stock-summary-card .summary-value { font-size: 20px; font-weight: 700; }
    .table-ledger th { vertical-align: middle; }
</style>
@endpush

@section('content')
<section class="forms">
    <div class="container-fluid">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h3 class="mb-0">{{ __('db.Stock Ledger') }}</h3>
                    <small class="text-muted">Transaction-by-transaction warehouse stock movements with running balance.</small>
                </div>
                <div class="mt-2 mt-md-0">
                    <a class="btn btn-outline-secondary btn-sm" target="_blank" href="{{ route('report.stock-ledger.print', request()->query()) }}"><i class="ti ti-printer"></i> Print</a>
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('report.stock-ledger.export', request()->query()) }}"><i class="ti ti-file-download"></i> CSV</a>
                </div>
            </div>
            <div class="card-body">
                <form method="get" action="{{ route('report.stock-ledger') }}" id="stock-ledger-filter-form">
                    {{-- Primary filters: Date Range | Warehouse | Product | Filter | Reset --}}
                    <div class="row align-items-end">
                        <div class="col-lg-3 col-md-6 form-group">
                            <label>{{ __('db.Choose Your Date') ?? 'Date Range' }}</label>
                            <div class="input-group">
                                <input type="text" class="daterangepicker-field form-control" value="{{ $filters['start_date'] }} To {{ $filters['end_date'] }}" required />
                                <input type="hidden" name="start_date" value="{{ $filters['start_date'] }}" />
                                <input type="hidden" name="end_date" value="{{ $filters['end_date'] }}" />
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 form-group">
                            <label>{{ __('db.Warehouse') }}</label>
                            <select class="form-control selectpicker" data-live-search="true" name="warehouse_id">
                                <option value="">{{ __('db.All Warehouse') }}</option>
                                @foreach($warehouses as $w)
                                    <option value="{{ $w->id }}" @selected((int)($filters['warehouse_id'] ?? 0) === (int)$w->id)>{{ $w->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-6 form-group">
                            <label>{{ __('db.Product') }}</label>
                            <select class="form-control selectpicker select2-searchable" data-live-search="true" name="product_id">
                                <option value="">{{ __('db.All') }}</option>
                                @foreach($products as $p)
                                    <option value="{{ $p->id }}" @selected((int)($filters['product_id'] ?? 0) === (int)$p->id)>{{ $p->name }} [{{ $p->code }}]</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-6 form-group d-flex align-items-end flex-wrap">
                            <button class="btn btn-primary mr-2 mb-1" type="submit"><i class="ti ti-filter"></i> {{ __('db.submit') }}</button>
                            <a href="{{ route('report.stock-ledger') }}" class="btn btn-light mr-2 mb-1">{{ __('db.Reset') }}</a>
                            <button class="btn btn-outline-secondary mb-1" type="button" data-toggle="collapse" data-target="#advancedFilters" aria-expanded="{{ !empty($filters['category_id']) || !empty($filters['brand_id']) || !empty($filters['variant_id']) || !empty($filters['product_batch_id']) || !empty($filters['imei']) || !empty($filters['source_type']) ? 'true' : 'false' }}">
                                <i class="ti ti-adjustments-horizontal"></i> {{ __('db.More Filters') }}
                            </button>
                        </div>
                    </div>

                    {{-- Advanced / More Filters: Category | Brand | Variant | Batch | IMEI/Serial | Source Type --}}
                    @php
                        $hasAdvanced = !empty($filters['category_id']) || !empty($filters['brand_id']) || !empty($filters['variant_id']) || !empty($filters['product_batch_id']) || !empty($filters['imei']) || !empty($filters['source_type']);
                    @endphp
                    <div class="collapse {{ $hasAdvanced ? 'show' : '' }} mt-3 pt-3 border-top" id="advancedFilters">
                        <div class="row">
                            <div class="col-lg-2 col-md-4 form-group">
                                <label>{{ __('db.category') }}</label>
                                <select class="form-control selectpicker" data-live-search="true" name="category_id">
                                    <option value="">{{ __('db.All') }}</option>
                                    @foreach($categories as $c)
                                        <option value="{{ $c->id }}" @selected((int)($filters['category_id'] ?? 0) === (int)$c->id)>{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 form-group">
                                <label>{{ __('db.Brand') }}</label>
                                <select class="form-control selectpicker" data-live-search="true" name="brand_id">
                                    <option value="">{{ __('db.All') }}</option>
                                    @foreach($brands as $b)
                                        <option value="{{ $b->id }}" @selected((int)($filters['brand_id'] ?? 0) === (int)$b->id)>{{ $b->title }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 form-group">
                                <label>Variant</label>
                                <select class="form-control selectpicker" data-live-search="true" name="variant_id">
                                    <option value="">{{ __('db.All') }}</option>
                                    @foreach($variants as $v)
                                        <option value="{{ $v->id }}" @selected((int)($filters['variant_id'] ?? 0) === (int)$v->id)>{{ $v->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 form-group">
                                <label>Batch</label>
                                <select class="form-control selectpicker" data-live-search="true" name="product_batch_id">
                                    <option value="">{{ __('db.All') }}</option>
                                    @foreach($batches as $batch)
                                        <option value="{{ $batch->id }}" @selected((int)($filters['product_batch_id'] ?? 0) === (int)$batch->id)>{{ $batch->batch_no }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-4 form-group">
                                <label>IMEI / Serial</label>
                                <input class="form-control" name="imei" value="{{ $filters['imei'] ?? '' }}" placeholder="IMEI or serial">
                            </div>
                            <div class="col-lg-2 col-md-4 form-group">
                                <label>Source Type</label>
                                <select class="form-control selectpicker" data-live-search="true" name="source_type">
                                    <option value="">{{ __('db.All') }}</option>
                                    @foreach($sourceTypes as $key => $label)
                                        <option value="{{ $key }}" @selected(($filters['source_type'] ?? '') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- Summary Cards: Opening Qty | Total In | Total Out | Closing Qty --}}
        <div class="row mb-3">
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="card h-100 border-0 shadow-sm stock-summary-card">
                    <div class="card-body text-center py-3">
                        <div class="summary-label">{{ __('db.Opening Qty') }}</div>
                        <div class="summary-value text-secondary">{{ number_format($summary['opening_qty'] ?? 0, 4) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="card h-100 border-0 shadow-sm stock-summary-card">
                    <div class="card-body text-center py-3">
                        <div class="summary-label">{{ __('db.Total In') }}</div>
                        <div class="summary-value text-success">+{{ number_format($summary['total_in'] ?? $summary['qty_in'] ?? 0, 4) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="card h-100 border-0 shadow-sm stock-summary-card">
                    <div class="card-body text-center py-3">
                        <div class="summary-label">{{ __('db.Total Out') }}</div>
                        <div class="summary-value text-danger">-{{ number_format($summary['total_out'] ?? $summary['qty_out'] ?? 0, 4) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
                <div class="card h-100 border-0 shadow-sm stock-summary-card">
                    <div class="card-body text-center py-3">
                        <div class="summary-label">{{ __('db.Closing Qty') }}</div>
                        <div class="summary-value text-primary">{{ number_format($summary['closing_qty'] ?? 0, 4) }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Movement Ledger Table --}}
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <strong>{{ __('db.Movement Ledger') }}</strong>
                    <span class="text-muted ml-2">({{ $start_date }} to {{ $end_date }})</span>
                </div>
                <div class="d-flex align-items-center mt-2 mt-md-0">
                    <span class="text-muted small mr-3">Showing {{ $movements->firstItem() ?? 0 }} to {{ $movements->lastItem() ?? 0 }} of {{ $movements->total() }} entries</span>
                    <form method="get" action="{{ route('report.stock-ledger') }}" class="d-inline-block">
                        @foreach(request()->except(['page', 'per_page']) as $k => $v)
                            @if(is_array($v))
                                @foreach($v as $subV)<input type="hidden" name="{{ $k }}[]" value="{{ $subV }}">@endforeach
                            @elseif($v !== null && $v !== '')
                                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                            @endif
                        @endforeach
                        <select class="form-control form-control-sm d-inline-block w-auto" name="per_page" onchange="this.form.submit()">
                            @foreach([25, 50, 100, 200] as $n)
                                <option value="{{ $n }}" @selected((int)request('per_page', 50) === $n)>{{ $n }} / page</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-striped table-hover table-ledger mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">Date/Time</th>
                            <th style="min-width: 170px;">{{ __('db.Source / Reference') }}</th>
                            <th style="min-width: 130px;">{{ __('db.Warehouse') }}</th>
                            <th style="min-width: 220px;">{{ __('db.Product') }}</th>
                            <th style="min-width: 130px;">{{ __('db.Batch / IMEI') }}</th>
                            <th class="text-right" style="min-width: 100px;">{{ __('db.In Qty') }}</th>
                            <th class="text-right" style="min-width: 100px;">{{ __('db.Out Qty') }}</th>
                            <th class="text-right" style="min-width: 110px;">{{ __('db.Balance Qty') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($movements as $row)
                        @php
                            $sourceUrl = \App\Http\Controllers\StockLedgerController::sourceUrl($row['source_type'], (int)$row['source_id']);
                        @endphp
                        <tr>
                            <td class="text-nowrap">{{ $row['movement_at'] }}</td>
                            <td>
                                <span class="badge badge-light border">{{ ucwords(str_replace('_',' ',$row['source_type'])) }}</span><br>
                                @if($sourceUrl)
                                    <a href="{{ $sourceUrl }}" class="font-weight-bold text-primary" title="View details" target="_blank">{{ $row['reference_no'] ?: '#'.$row['source_id'] }}</a>
                                @else
                                    <strong>{{ $row['reference_no'] ?: '#'.$row['source_id'] }}</strong>
                                @endif
                                <small class="text-muted">#{{ $row['source_id'] }}</small>
                                @if($row['note'])<br><small class="text-muted">{{ $row['note'] }}</small>@endif
                            </td>
                            <td>{{ $row['warehouse_name'] }}</td>
                            <td>
                                <strong>{{ $row['product_name'] }}</strong><br>
                                <small class="text-muted">{{ $row['product_code'] }}@if($row['variant_name']) · {{ $row['variant_name'] }}@endif @if($row['uom']) · {{ $row['uom'] }}@endif</small>
                                @if($row['category_name'] || $row['brand_name'])
                                    <br><small class="text-muted">{{ $row['category_name'] ?? '—' }} / {{ $row['brand_name'] ?? '—' }}</small>
                                @endif
                            </td>
                            <td>
                                @if($row['batch_no'])<div><small class="badge badge-secondary">Batch: {{ $row['batch_no'] }}</small></div>@endif
                                @if($row['imei_number'])<small class="text-muted">IMEI/Serial: {{ $row['imei_number'] }}</small>@endif
                                @if(!$row['batch_no'] && !$row['imei_number'])<span class="text-muted">—</span>@endif
                            </td>
                            <td class="text-right font-weight-bold text-success">{{ $row['qty_in'] ? '+'.number_format($row['qty_in'], 4) : '' }}</td>
                            <td class="text-right font-weight-bold text-danger">{{ $row['qty_out'] ? '-'.number_format($row['qty_out'], 4) : '' }}</td>
                            <td class="text-right font-weight-bold">{{ number_format($row['running_balance'], 4) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center py-4 text-muted">No stock movements match the selected filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($movements->hasPages())
                <div class="card-footer d-flex justify-content-between align-items-center flex-wrap">
                    <span class="text-muted small">Showing page {{ $movements->currentPage() }} of {{ $movements->lastPage() }}</span>
                    {{ $movements->links() }}
                </div>
            @endif
        </div>

        {{-- Stock Reconciliation Card --}}
        <div class="card">
            <div class="card-header">
                <strong>Stock Reconciliation</strong><br>
                <small class="text-muted">Closing balance is reconstructed from current warehouse stock and movements after the selected end date. This keeps historical periods traceable without inventing an opening transaction.</small>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('db.Warehouse') }}</th>
                            <th>{{ __('db.Product') }}</th>
                            <th class="text-right">{{ __('db.Opening Qty') }}</th>
                            <th class="text-right">{{ __('db.In Qty') }}</th>
                            <th class="text-right">{{ __('db.Out Qty') }}</th>
                            <th class="text-right">Closing as of {{ $end_date }}</th>
                            <th class="text-right">Current</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($stock_summary as $row)
                        <tr>
                            <td>{{ $row['warehouse_name'] }}</td>
                            <td>{{ $row['product_name'] }} [{{ $row['product_code'] }}]@if($row['variant_name']) · {{ $row['variant_name'] }}@endif @if($row['batch_no']) · Batch {{ $row['batch_no'] }}@endif</td>
                            <td class="text-right">{{ number_format($row['opening_balance'], 4) }}</td>
                            <td class="text-right text-success">{{ number_format($row['qty_in'], 4) }}</td>
                            <td class="text-right text-danger">{{ number_format($row['qty_out'], 4) }}</td>
                            <td class="text-right font-weight-bold">{{ number_format($row['closing_balance'], 4) }}</td>
                            <td class="text-right">{{ number_format($row['current_stock'], 4) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">No stock keys found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script type="text/javascript">
    $(document).ready(function() {
        $('.daterangepicker-field').on('apply.daterangepicker', function(ev, picker) {
            $('input[name="start_date"]').val(picker.startDate.format('YYYY-MM-DD'));
            $('input[name="end_date"]').val(picker.endDate.format('YYYY-MM-DD'));
        });
    });
</script>
@endpush
