@extends('backend.layout.main')

@section('content')
<section class="container-fluid py-3">
    <a href="{{ route('accounting.reconciliation.index') }}#payment-account-mappings-details">&larr; {{ __('db.accounting_health_mapping_back_to_health') }}</a>
    <div class="card mt-3"><div class="card-body">
        <span class="badge badge-warning">{{ __('db.accounting_health_status_setup_required') }}</span>
        <h3 class="mt-2">{{ __('db.accounting_health_mapping_workspace_title') }}</h3>
        <p>{{ __('db.accounting_health_mapping_workspace_intro') }}</p>
        <div class="row">
            <div class="col-md-3"><strong>{{ __('db.accounting_health_mapping_found') }}</strong><p>{{ __('db.accounting_health_mapping_found_text', ['count' => $inspection['missing_count'] + $inspection['invalid_count']]) }}</p></div>
            <div class="col-md-3"><strong>{{ __('db.accounting_health_mapping_why') }}</strong><p>{{ __('db.accounting_health_mapping_why_text') }}</p></div>
            <div class="col-md-3"><strong>{{ __('db.accounting_health_mapping_calculation') }}</strong><p>{{ __('db.accounting_health_mapping_calculation_text') }}</p></div>
            <div class="col-md-3"><strong>{{ __('db.accounting_health_mapping_verify_resolution') }}</strong><p>{{ __('db.accounting_health_mapping_verify_text') }}</p></div>
        </div>
    </div></div>

    @foreach($inspection['repairable'] as $item)
    <div class="card mt-3" id="mapping-account-{{ $item['account_id'] }}"><div class="card-body">
        <span class="badge badge-success">{{ __('db.accounting_health_status_safe_repair') }}</span>
        <h4 class="mt-2">{{ $item['account_name'] }}</h4>
        <dl class="row">
            <dt class="col-sm-3">{{ __('db.accounting_health_mapping_payment_type') }}</dt><dd class="col-sm-9">{{ $item['account_type'] ?: __('db.accounting_health_mapping_not_recorded') }}</dd>
            <dt class="col-sm-3">{{ __('db.accounting_health_mapping_current_usage') }}</dt><dd class="col-sm-9">{{ collect($item['evidence']['usage'] ?? [])->sum() }} {{ __('db.accounting_health_mapping_related_records') }}</dd>
            <dt class="col-sm-3">{{ __('db.accounting_health_mapping_scope') }}</dt><dd class="col-sm-9">{{ __('db.accounting_health_mapping_global_base_scope') }}</dd>
            <dt class="col-sm-3">{{ __('db.accounting_health_mapping_recommendation') }}</dt><dd class="col-sm-9">{{ $item['expected']['code'] }} — {{ $item['expected']['name'] }} ({{ $item['expected']['parent_code'] }} {{ $item['expected']['parent_name'] }})</dd>
        </dl>
        <p><strong>{{ __('db.accounting_health_mapping_safe_reason_heading') }}</strong> {{ __('db.accounting_health_mapping_safe_reason') }}</p>
        <p>{{ __('db.accounting_health_mapping_will_not_change') }}</p>
        @if($guidedRepairEnabled && $canPreviewGuidedRepair)
        <form method="POST" action="{{ route('accounting.reconciliation.payment-mapping-repairs.preview', $item['account_id']) }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="mapping-workspace-{{ $item['account_id'] }}-{{ \Illuminate\Support\Str::uuid() }}">
            <div class="form-group">
                <label for="resolution-{{ $item['account_id'] }}">{{ __('db.accounting_health_mapping_safe_choice') }}</label>
                <select class="form-control" id="resolution-{{ $item['account_id'] }}" name="resolution">
                    <option value="create">{{ __('db.accounting_health_mapping_create_recommended', ['code' => $item['expected']['code']]) }}</option>
                    @if($compatibleLedgers)<option value="existing">{{ __('db.accounting_health_mapping_use_existing') }}</option>@endif
                </select>
            </div>
            @if($compatibleLedgers)
            <div class="form-group"><label for="ledger-{{ $item['account_id'] }}">{{ __('db.accounting_health_mapping_compatible_ledger') }}</label>
                <select class="form-control" id="ledger-{{ $item['account_id'] }}" name="ledger_account_id">
                    @foreach($compatibleLedgers as $ledger)<option value="{{ $ledger['id'] }}">{{ $ledger['code'] }} — {{ $ledger['name'] }}</option>@endforeach
                </select>
            </div>
            @endif
            <input type="hidden" name="backup_confirmed" value="1">
            <button class="btn btn-primary" type="submit">{{ __('db.accounting_health_mapping_preview_repair') }}</button>
        </form>
        @else
            <div class="alert alert-info">{{ __('db.accounting_health_mapping_ask_authorized_admin') }}</div>
        @endif
        @if($canViewTechnicalDetails)<details class="mt-3"><summary>{{ __('db.accounting_health_mapping_technical_details') }}</summary><code>{{ __('db.accounting_health_mapping_internal_account_id', ['id' => $item['account_id']]) }}</code></details>@endif
    </div></div>
    @endforeach

    @if($inspection['ambiguous'] || $inspection['invalid'] || $inspection['unsupported'])
    <div class="card mt-3"><div class="card-body"><span class="badge badge-warning">{{ __('db.accounting_health_status_manual_review') }}</span>
        <h4 class="mt-2">{{ __('db.accounting_health_mapping_manual_review_title') }}</h4>
        <p>{{ __('db.accounting_health_mapping_manual_review_explanation') }}</p>
        <ol><li>{{ __('db.accounting_health_mapping_check_usage') }}</li><li>{{ __('db.accounting_health_mapping_check_economic_meaning') }}</li><li>{{ __('db.accounting_health_mapping_check_currency_scope') }}</li><li>{{ __('db.accounting_health_mapping_obtain_accountant') }}</li></ol>
        @foreach(array_merge($inspection['ambiguous'], $inspection['invalid'], $inspection['unsupported']) as $item)
            <div class="alert alert-warning"><strong>{{ $item['account_name'] }}</strong> — {{ $item['review_reason'] ?? $item['reason'] }}
            @if(!empty($item['owner_question']))<p><strong>{{ __('db.accounting_health_mapping_owner_question') }}</strong></p>
                @if($guidedRepairEnabled && $canPreviewGuidedRepair)<form method="POST" action="{{ route('accounting.reconciliation.payment-mapping-repairs.preview', $item['account_id']) }}">@csrf
                    <input type="hidden" name="idempotency_key" value="owner-classification-{{ $item['account_id'] }}-{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="resolution" value="owner_classification"><input type="hidden" name="backup_confirmed" value="1">
                    <label><input type="radio" name="owner_classification" value="cash" required> {{ __('db.accounting_health_mapping_owner_cash') }}</label><br><label><input type="radio" name="owner_classification" value="owner_investment" required> {{ __('db.accounting_health_mapping_owner_capital') }}</label><br><button class="btn btn-primary btn-sm">{{ __('db.accounting_health_mapping_answer_continue') }}</button></form>
                @else<strong>{{ __('db.accounting_health_mapping_owner_permission_required') }}</strong>@endif
            @endif</div>
        @endforeach
        <p>{{ __('db.accounting_health_mapping_manual_resolution_evidence') }}</p>
    </div></div>
    @endif

    @foreach($plans as $plan)
        @php($mutation = $plan->proposed_mutations[0] ?? [])
        <div class="card mt-3" id="plan-{{ $plan->plan_key }}"><div class="card-body">
            <span class="badge badge-info">{{ ucfirst($plan->status) }}</span><h4>{{ $mutation['payment_account_name'] ?? __('db.accounting_health_mapping_payment_account') }}</h4>
            <p>{{ $mutation['ledger_account_code'] ?? '' }} — {{ $mutation['ledger_account_name'] ?? '' }}</p>
            <p>{{ __('db.accounting_health_mapping_expires', ['timestamp' => $plan->expires_at]) }}</p>
            @if($plan->status === 'previewed' && $canApproveGuidedRepair)
                <form method="POST" action="{{ route('accounting.reconciliation.repairs.approve', $plan) }}">@csrf<input type="hidden" name="backup_confirmed" value="1"><button class="btn btn-outline-primary">{{ __('db.accounting_health_mapping_approve') }}</button></form>
            @elseif($plan->status === 'approved' && $canExecuteGuidedRepair)
                <button class="btn btn-primary" data-toggle="modal" data-target="#confirm-plan-{{ $plan->id }}">{{ __('db.accounting_health_mapping_confirm_repair') }}</button>
                <div class="modal fade" id="confirm-plan-{{ $plan->id }}" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title">{{ __('db.accounting_health_mapping_confirm_repair') }}</h5><button class="close" data-dismiss="modal"><span>&times;</span></button></div>
                    <div class="modal-body"><p>{{ __('db.accounting_health_mapping_final_confirmation') }}</p></div>
                    <div class="modal-footer"><button class="btn btn-secondary" data-dismiss="modal">{{ __('db.Cancel') }}</button><form method="POST" action="{{ route('accounting.reconciliation.repairs.execute', $plan) }}">@csrf<button class="btn btn-primary">{{ __('db.accounting_health_mapping_confirm_repair') }}</button></form></div>
                </div></div></div>
            @elseif($plan->status === 'completed')
                <div class="alert alert-success">{{ __('db.accounting_health_mapping_verification_passed') }}</div>
            @endif
            @if($canViewTechnicalDetails)<details><summary>{{ __('db.accounting_health_mapping_technical_details') }}</summary><code>{{ __('db.accounting_health_mapping_plan_hash', ['hash' => $plan->preconditions['immutable_payload_hash'] ?? '']) }}</code></details>@endif
        </div></div>
    @endforeach
</section>
@endsection
