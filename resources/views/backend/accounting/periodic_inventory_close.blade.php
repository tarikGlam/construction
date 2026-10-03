@extends('backend.layout.main')
@section('content')
@php
    $hasActionableBlockingErrors = $preview['blocking_errors']->contains(
        fn ($error) => $preview['period_available'] || $error !== 'period_start_after_end'
    );
@endphp
<section class="container-fluid py-3">
    <div class="card"><div class="card-body">
        <span class="badge {{ $hasActionableBlockingErrors ? 'badge-danger' : 'badge-info' }}">{{ $hasActionableBlockingErrors ? __('db.accounting_health_status_action_required') : __('db.accounting_health_status_operational_review') }}</span>
        <h3 class="mt-2">{{ __('db.inventory_close_title') }}</h3>
        <p>{{ __('db.inventory_close_intro') }}</p>
        <div class="alert alert-info">
            <strong>Inventory valuation is separate from accounting period locking.</strong>
            Use this screen when you want SalePro to reconcile operational stock value to the Inventory ledger and recognize the resulting periodic COGS adjustment. It is not a mandatory monthly accounting close and does not lock transactions.
        </div>
        <div class="alert alert-warning">{{ __('db.inventory_close_current_state_warning') }}</div>
        @if($preview['period_available'])
        <form method="GET" class="form-row">
            <div class="col-md-4"><label>{{ $preview['is_first_close'] ? __('db.inventory_close_first_close_label') : __('db.inventory_close_period_start') }}</label><input class="form-control" type="date" name="period_start" value="{{ $preview['period_start'] }}" readonly><small>{{ $preview['is_first_close'] ? __('db.inventory_close_first_close_help') : __('db.inventory_close_next_close_help') }}</small></div>
            <div class="col-md-4"><label>{{ __('db.inventory_close_period_end') }}</label><input class="form-control" type="date" name="period_end" value="{{ $preview['period_end'] }}"></div>
            <div class="col-md-4 align-self-end"><button class="btn btn-primary">{{ __('db.inventory_close_preview') }}</button></div>
        </form>
        @else
            <div class="alert alert-success mb-0">
                @if($preview['covered_through'])
                    {{ __('db.inventory_close_up_to_date', ['date' => $preview['covered_through'], 'start' => $preview['period_start']]) }}
                @else
                    {{ __('db.inventory_close_not_started', ['start' => $preview['period_start']]) }}
                @endif
            </div>
        @endif
    </div></div>
    @foreach($preview['blocking_errors'] as $error)
        @if($preview['period_available'] || $error !== 'period_start_after_end')
            <div class="alert alert-danger mt-2">{{ __('db.inventory_close_block_'.$error) }}</div>
        @endif
    @endforeach

    <div class="card mt-3"><div class="card-body">
        <h4>{{ __('db.inventory_close_values_title') }}</h4>
        <p>{{ __('db.inventory_close_accounting_inventory_help') }}</p><p>{{ __('db.inventory_close_operational_inventory_help') }}</p>
        <div class="table-responsive"><table class="table table-bordered">
            <tr><th>{{ __('db.inventory_close_book_inventory') }}</th><td>{{ number_format($preview['book_inventory'], 2) }}</td></tr>
            <tr><th>{{ __('db.inventory_close_operational_inventory') }}</th><td>{{ number_format($preview['operational_inventory'], 2) }}</td></tr>
            <tr><th>{{ __('db.inventory_close_proposed_adjustment') }}</th><td>{{ number_format($preview['adjustment'], 2) }}</td></tr>
        </table></div>
        <p class="lead"><strong>{{ number_format($preview['formula']['accounting_inventory'], 2) }} − {{ number_format($preview['formula']['operational_inventory'], 2) }} = {{ number_format($preview['formula']['difference'], 2) }}</strong></p>
        <p>{{ __('db.inventory_close_normal_close_note') }}</p>
    </div></div>

    <div class="card mt-3"><div class="card-body"><h4>{{ __('db.inventory_close_calculation_title') }}</h4>
        <dl class="row">@foreach($preview['valuation_basis'] as $key => $value) @if($key !== 'historical_reconstruction_supported')<dt class="col-sm-4">{{ __('db.inventory_close_basis_'.$key) }}</dt><dd class="col-sm-8">{{ $value }}</dd>@endif @endforeach</dl>
        <div class="alert alert-info">{{ __('db.inventory_close_historical_limitation') }}</div>
    </div></div>

    <div class="card mt-3"><div class="card-body"><h4>{{ __('db.inventory_close_breakdown_title') }}</h4>
        <h5>{{ __('db.inventory_close_warehouse_breakdown') }}</h5><table class="table table-sm">@foreach($preview['breakdown']['warehouses'] as $warehouse)<tr><td>{{ $warehouse['warehouse'] }}</td><td>{{ number_format($warehouse['value'], 2) }}</td></tr>@endforeach</table>
        <div class="row"><div class="col-md-4"><strong>{{ __('db.inventory_close_negative_stock') }}</strong><p>{{ $preview['valuation']['negative_stock']->count() }}</p></div><div class="col-md-4"><strong>{{ __('db.inventory_close_missing_cost') }}</strong><p>{{ $preview['valuation']['missing_cost']->count() }}</p></div><div class="col-md-4"><strong>{{ __('db.inventory_close_quantity_mismatch') }}</strong><p>{{ $preview['valuation']['quantity_mismatches']->count() }}</p></div></div>
        <h5>{{ __('db.inventory_close_top_contributors') }}</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('db.Product') }}</th><th>{{ __('db.Warehouse') }}</th><th>{{ __('db.Quantity') }}</th><th>{{ __('db.Cost') }}</th><th>{{ __('db.Amount') }}</th></tr></thead><tbody>
            @foreach($preview['breakdown']['top_contributors'] as $item)<tr><td>{{ $item->code }} — {{ $item->name }}</td><td>{{ $item->warehouse }}</td><td>{{ number_format($item->qty, 4) }}</td><td>{{ number_format($item->cost, 4) }}</td><td>{{ number_format($item->value, 2) }}</td></tr>@endforeach
        </tbody></table></div><small>{{ __('db.inventory_close_breakdown_limit', ['count' => $preview['breakdown']['sample_limit']]) }}</small>
    </div></div>

    <div class="card mt-3"><div class="card-body"><h4>{{ __('db.inventory_close_review_title') }}</h4><ol>
        <li>{{ __('db.inventory_close_check_physical') }}</li><li>{{ __('db.inventory_close_check_negative') }}</li><li>{{ __('db.inventory_close_check_cost') }}</li><li>{{ __('db.inventory_close_check_movements') }}</li><li>{{ __('db.inventory_close_check_date') }}</li><li>{{ __('db.inventory_close_check_journal') }}</li><li>{{ __('db.inventory_close_check_backup') }}</li><li>{{ __('db.inventory_close_check_approval') }}</li>
    </ol></div></div>

    <div class="card mt-3"><div class="card-body"><h4>{{ __('db.inventory_close_entry_title') }}</h4>
        @if($preview['proposed_entry'])
            <table class="table"><tr><th>{{ __('db.inventory_close_debit') }}</th><td>{{ $preview['proposed_entry']['debit']['code'] }} — {{ $preview['proposed_entry']['debit']['name'] }}</td><td>{{ number_format($preview['proposed_entry']['debit']['amount'], 2) }}</td></tr><tr><th>{{ __('db.inventory_close_credit') }}</th><td>{{ $preview['proposed_entry']['credit']['code'] }} — {{ $preview['proposed_entry']['credit']['name'] }}</td><td>{{ number_format($preview['proposed_entry']['credit']['amount'], 2) }}</td></tr></table>
        @else<div class="alert alert-success">{{ __('db.inventory_close_zero_entry') }}</div>@endif
        @if($preview['can_post'])<button class="btn btn-success" data-toggle="modal" data-target="#inventory-close-confirmation">{{ __('db.inventory_close_confirm_close') }}</button>
        @elseif($preview['active_close'])<div class="alert alert-info">{{ __('db.inventory_close_already_posted') }}</div>@endif
    </div></div>

    @if($preview['can_post'])
    <div class="modal fade" id="inventory-close-confirmation" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">{{ __('db.inventory_close_confirm_title') }}</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div>
        <div class="modal-body"><p>{{ __('db.inventory_close_confirm_text') }}</p></div>
        <div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">{{ __('db.Cancel') }}</button><form method="POST" action="{{ route('accounting.inventory-close.store') }}">@csrf<input type="hidden" name="period_start" value="{{ $preview['period_start'] }}"><input type="hidden" name="period_end" value="{{ $preview['period_end'] }}"><input type="hidden" name="confirmation" value="1"><button class="btn btn-success">{{ __('db.inventory_close_complete_close') }}</button></form></div>
    </div></div></div>
    @endif

    <div class="card mt-3"><div class="card-body"><h4>{{ __('db.inventory_close_history') }}</h4><table class="table"><tbody>
        @foreach($history as $close)<tr><td>{{ $close->period_start->toDateString() }} — {{ $close->period_end->toDateString() }}</td><td>{{ __('db.inventory_close_status_'.$close->status) }}</td><td>{{ number_format($close->adjustment, 2) }}</td><td>{{ $close->journalEntry?->reference_no ?: __('db.inventory_close_no_journal') }}</td><td>{{ $closers[$close->created_by] ?? __('db.accounting_health_orphan_unknown') }}<br>{{ optional($close->posted_at)->toDateTimeString() }}</td><td>{{ ($close->status === 'zero' || $close->journalEntry) ? __('db.inventory_close_verified') : __('db.inventory_close_not_verified') }}</td><td>@if(in_array($close->status, ['posted','zero']))<button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#reverse-close-{{ $close->id }}">{{ __('db.inventory_close_reverse') }}</button>@endif</td></tr>
        @if(in_array($close->status, ['posted','zero']))<div class="modal fade" id="reverse-close-{{ $close->id }}" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">{{ __('db.inventory_close_reverse_confirm_title') }}</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div><div class="modal-body">{{ __('db.inventory_close_reverse_confirm_text') }}</div><div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">{{ __('db.Cancel') }}</button><form method="POST" action="{{ route('accounting.inventory-close.reverse', $close) }}">@csrf<input type="hidden" name="confirmation" value="1"><button class="btn btn-warning">{{ __('db.inventory_close_reverse') }}</button></form></div></div></div></div>@endif
        @endforeach
    </tbody></table></div></div>
</section>
@endsection
