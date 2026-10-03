@extends('backend.layout.main')

@push('css')
    @include('backend.layout.partials.datatable_css')
@endpush

@section('content')
    <x-success-message key="message" />
    <x-error-message key="not_permitted" />

    <section>
        <div class="container-fluid mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h3 class="font-weight-bold text-primary mb-1">
                        <i class="ti ti-package-import"></i> {{ __('db.Import / Landed Cost Batches') }}
                    </h3>
                    <p class="text-muted small mb-0">
                        {{ __('db.Manage container and import batch landed costs, FIFO stock layers, and container profitability.') }}
                    </p>
                </div>
                <div class="mt-2 mt-md-0">
                    @can('import_batch-add')
                    <a href="{{ route('import-batches.create') }}" class="btn btn-primary">
                        <i class="ti ti-plus"></i> {{ __('db.Create Import Batch') }}
                    </a>
                    @endcan
                </div>
            </div>
        </div>

        <div class="container-fluid">
            <!-- Filter Bar -->
            <div class="card mb-3 shadow-sm border-0">
                <div class="card-body p-3">
                    <form method="GET" action="{{ route('import-batches.index') }}" class="row align-items-end">
                        <div class="col-md-3 mb-2">
                            <label class="form-label small text-muted">{{ __('db.Warehouse') }}</label>
                            <select name="warehouse_id" class="form-control selectpicker" data-live-search="true">
                                <option value="">{{ __('db.All Warehouses') }}</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" {{ request('warehouse_id') == $warehouse->id ? 'selected' : '' }}>
                                        {{ $warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="form-label small text-muted">{{ __('db.Status') }}</label>
                            <select name="status" class="form-control">
                                <option value="">{{ __('db.All Statuses') }}</option>
                                <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>{{ __('db.Draft') }}</option>
                                <option value="finalized" {{ request('status') === 'finalized' ? 'selected' : '' }}>{{ __('db.Finalized') }}</option>
                                <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>{{ __('db.Closed') }}</option>
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="form-label small text-muted">{{ __('db.From Date') }}</label>
                            <input type="date" name="starting_date" class="form-control" value="{{ request('starting_date') }}">
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="form-label small text-muted">{{ __('db.To Date') }}</label>
                            <input type="date" name="ending_date" class="form-control" value="{{ request('ending_date') }}">
                        </div>
                        <div class="col-md-3 mb-2">
                            <button type="submit" class="btn btn-info w-100">
                                <i class="ti ti-filter"></i> {{ __('db.Filter') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Batches Table -->
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>{{ __('db.Batch #') }}</th>
                                    <th>{{ __('db.Container / Ref') }}</th>
                                    <th>{{ __('db.Title') }}</th>
                                    <th>{{ __('db.Warehouse') }}</th>
                                    <th>{{ __('db.Goods Cost') }}</th>
                                    <th>{{ __('db.Landed Cost') }}</th>
                                    <th>{{ __('db.Total Cost') }}</th>
                                    <th>{{ __('db.Status') }}</th>
                                    <th>{{ __('db.Locked') }}</th>
                                    <th class="text-right not-exported">{{ __('db.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($batches as $batch)
                                    @php($canSeeReceivingDetails = !$isRestricted || (int) $batch->warehouse_id === (int) $userWarehouseId)
                                    <tr>
                                        <td>
                                            <span class="font-weight-bold text-primary">{{ $batch->batch_number }}</span>
                                            <div class="text-muted small">{{ $batch->received_at ? $batch->received_at->format('Y-m-d') : '-' }}</div>
                                        </td>
                                        <td>{{ $canSeeReceivingDetails ? ($batch->reference_no ?: '-') : '-' }}</td>
                                        <td>
                                            <div class="font-weight-medium">{{ $batch->title }}</div>
                                            <div class="text-muted small">
                                                @if($canSeeReceivingDetails)
                                                    {{ $batch->purchases->count() }} {{ __('db.purchases linked') }}
                                                @endif
                                            </div>
                                        </td>
                                        <td>{{ $batch->warehouse ? $batch->warehouse->name : '-' }}</td>
                                        <td>
                                            <span class="font-weight-medium">
                                                {{ $canSeeReceivingDetails ? number_format((float)$batch->total_goods_cost, 2) : '-' }}
                                            </span>
                                            <span class="text-muted small">{{ $batch->baseCurrency?->code }}</span>
                                        </td>
                                        <td>
                                            <span class="text-warning font-weight-bold">
                                                {{ $canSeeReceivingDetails ? '+' . number_format((float)$batch->total_landed_cost, 2) : '-' }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="font-weight-bold text-success">
                                                {{ $canSeeReceivingDetails ? number_format((float)$batch->total_cost, 2) : '-' }}
                                            </span>
                                            <span class="text-muted small">{{ $batch->baseCurrency?->code }}</span>
                                        </td>
                                        <td>
                                            @if($batch->status === 'finalized')
                                                <span class="badge badge-success px-2 py-1">{{ __('db.Finalized') }}</span>
                                            @elseif($batch->status === 'closed')
                                                <span class="badge badge-secondary px-2 py-1">{{ __('db.Closed') }}</span>
                                            @else
                                                <span class="badge badge-warning text-dark px-2 py-1">{{ __('db.Draft') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($batch->is_locked)
                                                <span class="badge badge-danger px-2 py-1" title="Locked due to consumed/transferred stock">
                                                    <i class="ti ti-lock"></i> {{ __('db.Locked') }}
                                                </span>
                                            @else
                                                <span class="badge badge-light text-muted border px-2 py-1">
                                                    <i class="ti ti-lock-open"></i> {{ __('db.Open') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown">
                                                    {{ __('db.action') }} <span class="caret"></span>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-right edit-options dropdown-default">
                                                    @if($canSeeReceivingDetails)
                                                    @can('import_batch-landed-cost')
                                                    <li>
                                                        <a href="{{ route('import-batches.landed-cost', $batch->id) }}" class="btn btn-link">
                                                            <i class="ti ti-calculator"></i> {{ __('db.Landed Costs & Allocation') }}
                                                        </a>
                                                    </li>
                                                    @endcan
                                                    @endif
                                                    @can('import_batch-profit-report')
                                                    <li>
                                                        <a href="{{ route('import-batches.profitability', $batch->id) }}" class="btn btn-link">
                                                            <i class="ti ti-chart-arrows-vertical"></i> {{ __('db.Container Profitability') }}
                                                        </a>
                                                    </li>
                                                    @endcan
                                                    @if($canSeeReceivingDetails && !$batch->is_locked)
                                                        @can('import_batch-edit')
                                                        <li>
                                                            <a href="{{ route('import-batches.edit', $batch->id) }}" class="btn btn-link">
                                                                <i class="ti ti-edit"></i> {{ __('db.edit') }}
                                                            </a>
                                                        </li>
                                                        @endcan
                                                        @can('import_batch-delete')
                                                        <li>
                                                            <form method="POST" action="{{ route('import-batches.destroy', $batch->id) }}" style="display:inline;">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="btn btn-link text-danger" data-confirm="{{ __('db.Are you sure want to delete this batch?') }}" data-confirm-type="danger">
                                                                    <i class="ti ti-trash"></i> {{ __('db.delete') }}
                                                                </button>
                                                            </form>
                                                        </li>
                                                        @endcan
                                                    @endif
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">
                                            <i class="ti ti-package-off text-muted mb-2" style="font-size: 2rem;"></i>
                                            <div>{{ __('db.No import batches found.') }}</div>
                                            <div class="mt-2">
                                                <a href="{{ route('import-batches.create') }}" class="btn btn-outline-primary btn-sm">
                                                    <i class="ti ti-plus"></i> {{ __('db.Create First Import Batch') }}
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($batches->hasPages())
                    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                        <span class="text-muted small">
                            Showing {{ $batches->firstItem() }} to {{ $batches->lastItem() }} of {{ $batches->total() }} batches
                        </span>
                        {{ $batches->withQueryString()->links() }}
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
