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
                                <i class="ti ti-edit"></i> {{ __('db.Edit Import Batch') }} — {{ $batch->batch_number }}
                            </h4>
                            <div>
                                <a href="{{ route('import-batches.landed-cost', $batch->id) }}" class="btn btn-warning btn-sm mr-2">
                                    <i class="ti ti-calculator"></i> {{ __('db.Landed Costs') }}
                                </a>
                                <a href="{{ route('import-batches.index') }}" class="btn btn-outline-secondary btn-sm">
                                    <i class="ti ti-arrow-left"></i> {{ __('db.Back to List') }}
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            @if($batch->is_locked)
                                <div class="alert alert-warning">
                                    <i class="ti ti-lock"></i> <strong>{{ __('db.Batch is Locked') }}:</strong>
                                    {{ __('db.Stock from this batch has already been consumed, transferred, or sold. Header attributes and linked purchases cannot be altered.') }}
                                </div>
                            @endif

                            <form method="POST" action="{{ route('import-batches.update', $batch->id) }}">
                                @csrf
                                @method('PUT')

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Receiving Warehouse') }} *</label>
                                        <select name="warehouse_id" class="form-control selectpicker" data-live-search="true" {{ $batch->is_locked || $batch->purchases->isNotEmpty() ? 'readonly' : '' }} required>
                                            @foreach($warehouses as $warehouse)
                                                <option value="{{ $warehouse->id }}" {{ old('warehouse_id', $batch->warehouse_id) == $warehouse->id ? 'selected' : '' }}>
                                                    {{ $warehouse->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @if($batch->purchases->isNotEmpty())
                                            <small class="text-muted">{{ __('db.Warehouse is locked while purchases are linked.') }}</small>
                                        @endif
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Container / B/L / Reference No') }}</label>
                                        <input type="text" name="reference_no" class="form-control" value="{{ old('reference_no', $batch->reference_no) }}" {{ $batch->is_locked ? 'readonly' : '' }}>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Batch Title') }} *</label>
                                        <input type="text" name="title" class="form-control" value="{{ old('title', $batch->title) }}" required {{ $batch->is_locked ? 'readonly' : '' }}>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Default Landed Cost Allocation Method') }} *</label>
                                        <select name="allocation_method" class="form-control" required {{ $batch->is_locked ? 'disabled' : '' }}>
                                            <option value="purchase_value" {{ old('allocation_method', $batch->allocation_method) == 'purchase_value' ? 'selected' : '' }}>
                                                {{ __('db.By Purchase Value (Pro-rata by Goods Cost)') }}
                                            </option>
                                            <option value="quantity" {{ old('allocation_method', $batch->allocation_method) == 'quantity' ? 'selected' : '' }}>
                                                {{ __('db.By Quantity (Pro-rata by Unit Count)') }}
                                            </option>
                                            <option value="manual" {{ old('allocation_method', $batch->allocation_method) == 'manual' ? 'selected' : '' }}>
                                                {{ __('db.Manual Allocation (Custom Weights)') }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Received Date') }}</label>
                                        <input type="date" name="received_at" class="form-control" value="{{ old('received_at', $batch->received_at ? $batch->received_at->format('Y-m-d') : date('Y-m-d')) }}" {{ $batch->is_locked ? 'readonly' : '' }}>
                                    </div>

                                    <div class="col-md-4 mb-3">
                                        <label class="font-weight-bold">{{ __('db.Notes / Description') }}</label>
                                        <textarea name="notes" class="form-control" rows="1" {{ $batch->is_locked ? 'readonly' : '' }}>{{ old('notes', $batch->notes) }}</textarea>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <div class="mb-4">
                                    <h5 class="font-weight-bold text-dark mb-2">
                                        <i class="ti ti-shopping-cart"></i> {{ __('db.Linked Purchases from Warehouse') }} ({{ $batch->warehouse?->name }})
                                    </h5>
                                    <p class="text-muted small">
                                        {{ __('db.Purchases checked below are allocated into this container batch.') }}
                                    </p>

                                    <div class="table-responsive border rounded" style="max-height: 320px; overflow-y: auto;">
                                        <table class="table table-hover table-sm mb-0">
                                            <thead class="bg-light sticky-top">
                                                <tr>
                                                    <th width="40" class="text-center">#</th>
                                                    <th>{{ __('db.Reference') }}</th>
                                                    <th>{{ __('db.Date') }}</th>
                                                    <th>{{ __('db.Supplier') }}</th>
                                                    <th>{{ __('db.Items') }}</th>
                                                    <th class="text-right">{{ __('db.Grand Total') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @php
                                                    $linkedIds = $batch->purchases->pluck('id')->toArray();
                                                @endphp
                                                @forelse($availablePurchases as $p)
                                                    @php
                                                        $isLinked = in_array($p->id, $linkedIds);
                                                    @endphp
                                                    <tr class="{{ $isLinked ? 'table-primary-light' : '' }}">
                                                        <td class="text-center">
                                                            <input type="checkbox" name="purchase_ids[]" value="{{ $p->id }}" {{ $isLinked ? 'checked' : '' }} {{ $batch->is_locked ? 'disabled' : '' }}>
                                                        </td>
                                                        <td class="font-weight-bold">{{ $p->reference_no }}</td>
                                                        <td>{{ $p->created_at ? $p->created_at->format('Y-m-d') : '-' }}</td>
                                                        <td>{{ $p->supplier ? $p->supplier->name : '-' }}</td>
                                                        <td>{{ $p->item }}</td>
                                                        <td class="text-right font-weight-bold">
                                                            {{ number_format((float)$p->grand_total, 2) }} {{ $p->currency?->code }}
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-3">
                                                            {{ __('db.No eligible purchases found for this warehouse.') }}
                                                        </td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                @if(!$batch->is_locked)
                                    <div class="text-right">
                                        <button type="submit" class="btn btn-primary px-4 py-2">
                                            <i class="ti ti-check"></i> {{ __('db.Update Batch') }}
                                        </button>
                                    </div>
                                @endif
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
