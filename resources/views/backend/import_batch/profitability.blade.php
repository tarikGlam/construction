@extends('backend.layout.main')

@section('content')
    <x-success-message key="message" />
    <x-error-message key="not_permitted" />

    <section>
        <div class="container-fluid mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h3 class="font-weight-bold text-primary mb-1">
                        <i class="ti ti-chart-arrows-vertical"></i> {{ __('db.Container Profitability Report') }} — {{ $batch->batch_number }}
                    </h3>
                    <p class="text-muted small mb-0">
                        {{ $batch->title }} | {{ __('db.Receiving Warehouse') }}: <strong>{{ $batch->warehouse?->name }}</strong> |
                        {{ __('db.Currency') }}: <strong>{{ $report['currency_code'] }}</strong>
                    </p>
                </div>
                <div class="mt-2 mt-md-0">
                    @if(!$report['is_warehouse_scoped'] || (int) $batch->warehouse_id === (int) auth()->user()->warehouse_id)
                    <a href="{{ route('import-batches.landed-cost', $batch->id) }}" class="btn btn-outline-primary btn-sm mr-2">
                        <i class="ti ti-calculator"></i> {{ __('db.Landed Costs') }}
                    </a>
                    @endif
                    <a href="{{ route('import-batches.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-arrow-left"></i> {{ __('db.Back to List') }}
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid">
            @if($report['is_warehouse_scoped'])
                <div class="alert alert-info mb-3">
                    <i class="ti ti-building-warehouse"></i> <strong>{{ __('db.Warehouse-Scoped View') }}:</strong>
                    {{ __('db.You have warehouse-restricted access. This report displays sales, current stock, and realized gross profitability exclusively for') }}
                    <strong>{{ $report['scoped_warehouse_name'] }}</strong>.
                </div>
            @endif

            <!-- KPI Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3 mb-2">
                    <div class="card shadow-sm border-0 border-left border-primary p-3">
                        <div class="text-muted small text-uppercase font-weight-bold">{{ __('db.Realized Sales Revenue') }}</div>
                        <div class="h3 font-weight-bold text-dark mb-0">
                            {{ number_format($report['summary']['realized_revenue'], 2) }}
                            <span class="small text-muted">{{ $report['currency_code'] }}</span>
                        </div>
                        <div class="text-muted small mt-1">
                            {{ number_format($report['summary']['total_sold_qty'], 2) }} {{ __('db.units sold') }}
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-2">
                    <div class="card shadow-sm border-0 border-left border-warning p-3">
                        <div class="text-muted small text-uppercase font-weight-bold">{{ __('db.Realized Landed COGS') }}</div>
                        <div class="h3 font-weight-bold text-warning mb-0">
                            {{ number_format($report['summary']['realized_cogs'], 2) }}
                            <span class="small text-muted">{{ $report['currency_code'] }}</span>
                        </div>
                        <div class="text-muted small mt-1">
                            {{ __('db.Exact FIFO import cost layers') }}
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-2">
                    <div class="card shadow-sm border-0 border-left border-success p-3">
                        <div class="text-muted small text-uppercase font-weight-bold">{{ __('db.Realized Gross Profit') }}</div>
                        <div class="h3 font-weight-bold text-success mb-0">
                            {{ number_format($report['summary']['realized_gross_profit'], 2) }}
                            <span class="small text-muted">{{ $report['currency_code'] }}</span>
                        </div>
                        <div class="text-success font-weight-bold small mt-1">
                            <i class="ti ti-trending-up"></i> {{ number_format($report['summary']['gross_margin_pct'], 1) }}% {{ __('db.Gross Margin') }}
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-2">
                    <div class="card shadow-sm border-0 border-left border-info p-3">
                        <div class="text-muted small text-uppercase font-weight-bold">{{ __('db.Remaining Stock Value') }}</div>
                        <div class="h3 font-weight-bold text-info mb-0">
                            {{ number_format($report['summary']['unrealized_stock_valuation'], 2) }}
                            <span class="small text-muted">{{ $report['currency_code'] }}</span>
                        </div>
                        <div class="text-muted small mt-1">
                            {{ number_format($report['summary']['total_remaining_qty'], 2) }} / {{ number_format($report['summary']['total_received_qty'], 2) }} {{ __('db.units in stock') }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Warehouse Breakdown Table -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="ti ti-building-warehouse"></i> {{ __('db.Multi-Warehouse Stock & Profitability Breakdown') }}
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>{{ __('db.Warehouse') }}</th>
                                    <th class="text-center">{{ __('db.Inward Qty') }}</th>
                                    <th class="text-center">{{ __('db.Stock Qty') }}</th>
                                    <th class="text-center">{{ __('db.Sold Qty') }}</th>
                                    <th class="text-right">{{ __('db.Revenue') }}</th>
                                    <th class="text-right">{{ __('db.Landed COGS') }}</th>
                                    <th class="text-right font-weight-bold">{{ __('db.Gross Profit') }}</th>
                                    <th class="text-right">{{ __('db.Margin %') }}</th>
                                    <th class="text-right text-info font-weight-bold">{{ __('db.Stock Valuation') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($report['warehouse_breakdown'] as $w)
                                    <tr>
                                        <td class="font-weight-bold">{{ $w['warehouse_name'] }}</td>
                                        <td class="text-center">{{ number_format($w['received_qty'], 2) }}</td>
                                        <td class="text-center">
                                            <span class="badge {{ $w['remaining_qty'] > 0 ? 'badge-primary' : 'badge-light text-muted' }}">
                                                {{ number_format($w['remaining_qty'], 2) }}
                                            </span>
                                        </td>
                                        <td class="text-center">{{ number_format($w['sold_qty'], 2) }}</td>
                                        <td class="text-right font-weight-medium">{{ number_format($w['revenue'], 2) }}</td>
                                        <td class="text-right text-warning">{{ number_format($w['cogs'], 2) }}</td>
                                        <td class="text-right font-weight-bold {{ $w['realized_profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                            {{ number_format($w['realized_profit'], 2) }}
                                        </td>
                                        <td class="text-right">
                                            <span class="badge {{ $w['margin_pct'] >= 0 ? 'badge-success' : 'badge-danger' }} px-2 py-1">
                                                {{ number_format($w['margin_pct'], 1) }}%
                                            </span>
                                        </td>
                                        <td class="text-right text-info font-weight-bold">
                                            {{ number_format($w['stock_value'], 2) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">
                                            {{ __('db.No warehouse movement data recorded for this batch.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Product Breakdown Table -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="ti ti-packages"></i> {{ __('db.Product-by-Product Profitability Breakdown') }}
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>{{ __('db.Product') }}</th>
                                    <th>{{ __('db.Variant') }}</th>
                                    <th class="text-right">{{ __('db.Unit Goods') }}</th>
                                    <th class="text-right">{{ __('db.Unit Landed') }}</th>
                                    <th class="text-right font-weight-bold text-primary">{{ __('db.Final Unit Cost') }}</th>
                                    <th class="text-center">{{ __('db.Received') }}</th>
                                    <th class="text-center">{{ __('db.Stock') }}</th>
                                    <th class="text-center">{{ __('db.Sold') }}</th>
                                    <th class="text-right">{{ __('db.Revenue') }}</th>
                                    <th class="text-right">{{ __('db.COGS') }}</th>
                                    <th class="text-right font-weight-bold">{{ __('db.Profit') }}</th>
                                    <th class="text-right">{{ __('db.Margin') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($report['product_breakdown'] as $prod)
                                    <tr>
                                        <td>
                                            <div class="font-weight-bold">{{ $prod['product_name'] }}</div>
                                            <div class="text-muted small">{{ $prod['product_code'] }}</div>
                                        </td>
                                        <td>{{ $prod['variant_name'] ?: '-' }}</td>
                                        <td class="text-right">{{ number_format($prod['unit_purchase_cost'], 2) }}</td>
                                        <td class="text-right text-warning">+{{ number_format($prod['unit_landed_cost'], 2) }}</td>
                                        <td class="text-right font-weight-bold text-primary">{{ number_format($prod['total_unit_cost'], 2) }}</td>
                                        <td class="text-center">{{ number_format($prod['received_qty'], 2) }}</td>
                                        <td class="text-center">
                                            <span class="badge {{ $prod['remaining_qty'] > 0 ? 'badge-primary' : 'badge-light text-muted' }}">
                                                {{ number_format($prod['remaining_qty'], 2) }}
                                            </span>
                                        </td>
                                        <td class="text-center">{{ number_format($prod['sold_qty'], 2) }}</td>
                                        <td class="text-right font-weight-medium">{{ number_format($prod['revenue'], 2) }}</td>
                                        <td class="text-right text-warning">{{ number_format($prod['cogs'], 2) }}</td>
                                        <td class="text-right font-weight-bold {{ $prod['realized_profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                            {{ number_format($prod['realized_profit'], 2) }}
                                        </td>
                                        <td class="text-right">
                                            <span class="badge {{ $prod['margin_pct'] >= 0 ? 'badge-success' : 'badge-danger' }} px-2 py-1">
                                                {{ number_format($prod['margin_pct'], 1) }}%
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="text-center py-4 text-muted">
                                            {{ __('db.No products found for this batch.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
