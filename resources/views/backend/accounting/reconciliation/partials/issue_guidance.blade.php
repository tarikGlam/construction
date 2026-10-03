<h6 class="mt-3">{{ __('db.accounting_health_issue_what_found') }}</h6>
<p class="health-muted mb-0">{{ __('db.' . $issue['explanation_key'], $issue['explanation_params'] ?? []) }}</p>
<h6 class="mt-3">{{ __('db.accounting_health_issue_why_matters') }}</h6>
<p class="mb-0">{{ __('db.accounting_health_issue_generic_why') }}</p>
<h6 class="mt-3">{{ __('db.accounting_health_issue_affected_records') }}</h6>
<p class="mb-0">{{ __('db.accounting_health_issue_affected_count', ['count' => $issue['explanation_params']['count'] ?? 0]) }}</p>
<h6 class="mt-3">{{ __('db.accounting_health_issue_how_calculated') }}</h6>
<p class="mb-0">{{ __('db.accounting_health_issue_calculation_guidance') }}</p>
<h6 class="mt-3">{{ __('db.accounting_health_issue_what_to_check') }}</h6>
<ol class="mb-0"><li>{{ __('db.accounting_health_issue_check_source') }}</li><li>{{ __('db.accounting_health_issue_check_scope') }}</li><li>{{ __('db.accounting_health_issue_check_evidence') }}</li></ol>
<div class="health-next-step mt-3">
    <strong>{{ __('db.accounting_health_issue_recommended_resolution') }}</strong>
    <p class="mb-0 mt-1">
        @if($issue['key'] === 'inventory_close')
            {{ __('db.accounting_health_issue_next_inventory') }}
        @elseif($issue['key'] === 'orphan_journals')
            {{ __('db.accounting_health_issue_next_orphan') }}
        @elseif(in_array($issue['key'], ['failed_jobs', 'queue_processing'], true))
            {{ __('db.accounting_health_issue_next_queue') }}
        @elseif($issue['key'] === 'semantic_mappings')
            {{ __('db.accounting_health_issue_next_semantic') }}
        @else
            {{ __('db.accounting_health_issue_next_generic') }}
        @endif
    </p>
</div>
<p class="mt-3 mb-0"><strong>{{ __('db.accounting_health_issue_verify') }}</strong> {{ __('db.accounting_health_issue_verify_text') }}</p>
