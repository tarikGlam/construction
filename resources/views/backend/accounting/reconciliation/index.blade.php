@extends('backend.layout.main')

@push('css')
<style>
    .accounting-health-hero {
        border: 0;
        border-radius: 24px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .08);
        overflow: hidden;
    }
    .accounting-health-hero.green {
        background: linear-gradient(135deg, #ecfdf5 0%, #ffffff 68%);
        border-left: 8px solid #10b981;
    }
    .accounting-health-hero.yellow {
        background: linear-gradient(135deg, #fffbeb 0%, #ffffff 68%);
        border-left: 8px solid #f59e0b;
    }
    .accounting-health-hero.red {
        background: linear-gradient(135deg, #fef2f2 0%, #ffffff 68%);
        border-left: 8px solid #ef4444;
    }
    .accounting-health-hero.neutral {
        background: linear-gradient(135deg, #f8fafc 0%, #ffffff 68%);
        border-left: 8px solid #94a3b8;
    }
    .health-group-card,
    .health-issue-card,
    .health-activity-card {
        border: 1px solid #eef2f7;
        border-radius: 18px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, .05);
        height: 100%;
    }
    .health-status-pill {
        border-radius: 999px;
        display: inline-flex;
        font-size: 12px;
        font-weight: 700;
        padding: 6px 12px;
        text-transform: uppercase;
    }
    .health-status-pill.pass {
        background: #dcfce7;
        color: #166534;
    }
    .health-status-pill.warn {
        background: #fef3c7;
        color: #92400e;
    }
    .health-status-pill.fail {
        background: #fee2e2;
        color: #991b1b;
    }
    .health-status-pill.not_run {
        background: #e5e7eb;
        color: #374151;
    }
    .health-muted {
        color: #64748b;
    }
    .health-detail-list {
        background: #f8fafc;
        border-radius: 14px;
        margin-top: 16px;
        padding: 14px;
    }
    .health-detail-list + .health-detail-list {
        margin-top: 10px;
    }
    .health-technical-output {
        background: #0f172a !important;
        border-radius: 14px;
        color: #f8fafc !important;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        line-height: 1.55;
        max-height: 360px;
        overflow: auto;
        padding: 18px;
        white-space: pre-wrap;
    }
    .health-timeline-item {
        border-left: 3px solid #e2e8f0;
        padding: 0 0 18px 18px;
        position: relative;
    }
    .health-timeline-item:before {
        background: #7c5cc4;
        border-radius: 50%;
        content: "";
        height: 11px;
        left: -7px;
        position: absolute;
        top: 4px;
        width: 11px;
    }
    .health-action-card {
        border-left: 5px solid #f59e0b !important;
    }
    .health-action-card.fail {
        border-left-color: #ef4444 !important;
    }
    .health-next-step {
        background: #f8fafc;
        border-radius: 12px;
        padding: 14px;
    }
    .health-anchor-highlight {
        animation: healthHighlight 1.8s ease;
    }
    .health-details-target,
    .health-details-heading {
        scroll-margin-top: 110px;
    }
    .health-details-heading:focus {
        outline: 3px solid rgba(124, 92, 196, .45);
        outline-offset: 4px;
    }
    @keyframes healthHighlight {
        0%, 55% { box-shadow: 0 0 0 5px rgba(124, 92, 196, .22); }
        100% { box-shadow: 0 10px 30px rgba(15, 23, 42, .05); }
    }
    @media (prefers-reduced-motion: reduce) {
        .health-anchor-highlight { animation: none; }
    }
</style>
@endpush

@section('content')
@php
    $overall = $health['overall'];
    $certification = $health['certification'] ?? null;
@endphp

<section>
    <div class="container-fluid">
        <div id="health-navigation-status" class="alert alert-warning d-none" role="alert" tabindex="-1"></div>
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h3 class="mb-1">{{ __('db.accounting_health_title') }}</h3>
                <p class="health-muted mb-0">{{ __('db.accounting_health_intro') }}</p>
            </div>
            <div class="d-flex flex-wrap mt-3 mt-md-0">
                @if($canViewTechnicalDetails)
                    <button id="health-technical-toggle" class="btn btn-outline-secondary mr-2 health-details-trigger" type="button"
                        data-health-target="technical-details" data-health-toggle="true"
                        data-show-label="{{ __('db.accounting_health_action_show_technical_details') }}"
                        data-hide-label="{{ __('db.accounting_health_details_hide_technical') }}"
                        aria-expanded="{{ $technicalExpanded ? 'true' : 'false' }}" aria-controls="technical-details">
                        {{ $technicalExpanded ? __('db.accounting_health_details_hide_technical') : __('db.accounting_health_action_show_technical_details') }}
                    </button>
                @endif
                <form action="{{ route('accounting.reconciliation.run-health-check') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-primary" aria-label="{{ __('db.accounting_health_action_run_check') }}">
                        <i class="ti ti-heart-check"></i> {{ __('db.accounting_health_action_run_check') }}
                    </button>
                </form>
                @if($deepScanEnabled && $canLaunchDeepScan)
                    <button type="button" class="btn btn-outline-primary ml-2" data-toggle="modal" data-target="#deep-scan-confirmation" {{ $activeDiagnosticScan && in_array($activeDiagnosticScan->status, ['created', 'queued', 'running', 'paused']) ? 'disabled' : '' }}>{{ __('db.accounting_health_deep_start') }}</button>
                @endif
            </div>
        </div>

        @if(is_array($healthRunNotice))
            <div class="alert alert-warning mb-4" role="alert" id="health-run-notice">
                <h5>{{ __('db.accounting_health_notice_title') }}</h5>
                <p>{{ $healthRunNotice['message'] }}</p>
                <p class="mb-2"><strong>{{ __('db.accounting_health_reference') }}:</strong> {{ $healthRunNotice['reference'] }} · {{ $healthRunNotice['checked_at'] }}</p>
                <p class="mb-2">{{ __('db.accounting_health_summary', $healthRunNotice['summary']) }}</p>
                <a class="btn btn-outline-primary btn-sm" href="{{ $healthRunNotice['action_url'] }}">{{ $healthRunNotice['next_action'] }}</a>
                @if(isset($healthRunNotice['technical']))
                    <div class="mt-3"><strong>{{ __('db.accounting_health_admin_detail') }}:</strong>
                        {{ $healthRunNotice['technical']['failed_stage'] }} / {{ $healthRunNotice['technical']['failure_code'] }}
                    </div>
                @endif
            </div>
        @endif

        @if($deepSetupExpanded || ($canViewTechnicalDetails && ($deepScanEnabled || $activeDiagnosticScan)))
        <div class="card mb-4" id="deep-scan-card" @if($activeDiagnosticScan) data-status-url="{{ route('accounting.reconciliation.deep-scans.status', ['scan' => $activeDiagnosticScan->scan_key]) }}" @endif>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div><h5>{{ __('db.accounting_health_deep_title') }}</h5><p class="health-muted mb-0">{{ __('db.accounting_health_deep_description') }}</p></div>
                    <a class="btn btn-outline-primary btn-sm" href="{{ route('accounting.reconciliation.index', ['deep_setup' => 1]) }}#deep-scan-setup">{{ __('db.accounting_health_deep_set_up') }}</a>
                </div>

                @if(!$deepSetupExpanded && !$deepScanEnabled)
                    <div class="alert alert-info mb-0"><strong>{{ __('db.accounting_health_deep_normal_unavailable') }}</strong><p class="mb-0">{{ __('db.accounting_health_deep_normal_admin_required') }}</p></div>
                @endif

                @if($deepSetupExpanded)
                    <section id="deep-scan-setup" tabindex="-1">
                        <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">{{ __('db.accounting_health_deep_setup_title') }}</h5><a href="{{ route('accounting.reconciliation.index', ['deep_setup' => 1]) }}#deep-scan-setup">{{ __('db.accounting_health_deep_recheck_setup') }}</a></div>
                        @if(!$canConfigureDeepScan && !$canViewTechnicalDetails)
                            <div class="alert alert-info">{{ __('db.accounting_health_deep_nontechnical_requirements') }}</div>
                        @else
                            <div class="table-responsive mb-3"><table class="table table-sm" aria-label="{{ __('db.accounting_health_deep_setup_checklist') }}"><tbody>
                                <tr id="server-setup"><th>{{ __('db.accounting_health_deep_installation_capability') }}</th><td>{{ $deepScanAvailability['installation_available'] ? __('db.accounting_health_deep_ready') : __('db.accounting_health_deep_needs_server_setup') }}</td><td><a href="#server-setup-details">{{ __('db.accounting_health_deep_view_server_setup') }}</a></td></tr>
                                <tr><th>{{ __('db.accounting_health_deep_business_advanced') }}</th><td>{{ $deepScanAvailability['advanced_enabled'] ? __('db.accounting_health_deep_enabled') : __('db.accounting_health_deep_disabled') }}</td><td>{{ $deepScanAvailability['installation_available'] && $deepScanAvailability['config_schema_ready'] ? __('db.accounting_health_deep_enable_advanced') : __('db.accounting_health_deep_depends_installation') }}</td></tr>
                                <tr><th>{{ __('db.accounting_health_deep_business_scan') }}</th><td>{{ $deepScanAvailability['feature_enabled'] ? __('db.accounting_health_deep_enabled') : __('db.accounting_health_deep_disabled') }}</td><td>{{ $deepScanAvailability['advanced_enabled'] ? __('db.accounting_health_deep_enable_business_scan') : __('db.accounting_health_deep_depends_advanced') }}</td></tr>
                                <tr><th>{{ __('db.accounting_health_deep_background_processing') }}</th><td>{{ $deepScanAvailability['queue_configured'] ? __('db.accounting_health_deep_configured') : __('db.accounting_health_deep_not_ready') }}</td><td><a href="#background-processing-setup">{{ __('db.accounting_health_deep_view_queue_setup') }}</a></td></tr>
                                <tr><th>{{ __('db.accounting_health_deep_worker_status') }}</th><td>{{ $deepScanAvailability['worker_ready'] ? __('db.accounting_health_deep_ready') : __('db.accounting_health_deep_not_ready') }}</td><td>@if($deepScanAvailability['queue_configured'] && $canConfigureDeepScan)<form class="d-inline" method="POST" action="{{ route('accounting.reconciliation.deep-scan-worker-test') }}">@csrf<button class="btn btn-link btn-sm p-0" type="submit">{{ __('db.accounting_health_deep_test_again') }}</button></form>@else {{ __('db.accounting_health_deep_depends_queue') }} @endif</td></tr>
                                <tr><th>{{ __('db.accounting_health_deep_accounting_cutover') }}</th><td>{{ $deepScanAvailability['accounting_ready'] ? __('db.accounting_health_deep_ready') : __('db.accounting_health_deep_not_ready') }}</td><td>@can('accounting-activation-manage')<a href="{{ route('accounting.activation.index') }}">{{ __('db.accounting_health_deep_open_activation') }}</a>@else {{ __('db.accounting_health_deep_contact_administrator') }} @endcan</td></tr>
                                <tr><th>{{ __('db.accounting_health_deep_user_permission') }}</th><td>{{ $deepScanAvailability['permission_ready'] ? __('db.accounting_health_deep_ready') : __('db.accounting_health_deep_not_permitted') }}</td><td>{{ $deepScanAvailability['permission_ready'] ? __('db.accounting_health_deep_no_action_required') : __('db.accounting_health_deep_contact_administrator') }}</td></tr>
                            </tbody></table></div>

                            <div id="server-setup-details" class="border rounded p-3 mb-3">
                                <h6>{{ __('db.accounting_health_deep_server_setup_title') }}</h6><p>{{ __('db.accounting_health_deep_server_setup_explanation') }}</p>
                                <code>ACCOUNTING_HEALTH_ADVANCED_ENABLED=true</code><br><code>ACCOUNTING_DEEP_SCAN_ENABLED=true</code>
                            </div>
                            <div id="background-processing-setup" class="border rounded p-3 mb-3">
                                <h6>{{ __('db.accounting_health_deep_background_setup_title') }}</h6><p>{{ __('db.accounting_health_deep_background_setup_explanation') }}</p>
                                <p>{{ __('db.accounting_health_deep_supported_queue', ['connections' => implode(', ', $deepScanAvailability['supported_queues'])]) }}</p>
                                <code>QUEUE_CONNECTION=database</code><br><code>php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=120</code><br><code>php artisan schedule:run</code>
                            </div>
                            @if($canConfigureDeepScan && $deepScanAvailability['installation_available'] && $deepScanAvailability['config_schema_ready'])
                                <form id="business-opt-in" method="POST" action="{{ route('accounting.reconciliation.diagnostic-settings.update') }}" class="border rounded p-3 mb-3">@csrf
                                    <h6>{{ __('db.accounting_health_deep_business_settings') }}</h6>
                                    <input type="hidden" name="advanced_diagnostics_enabled" value="0"><label class="d-block"><input type="checkbox" name="advanced_diagnostics_enabled" value="1" {{ $deepScanAvailability['advanced_enabled'] ? 'checked' : '' }}> {{ __('db.accounting_health_deep_enable_advanced') }}</label>
                                    <input type="hidden" name="deep_scan_enabled" value="0"><label class="d-block"><input type="checkbox" name="deep_scan_enabled" value="1" {{ $deepScanAvailability['feature_enabled'] ? 'checked' : '' }}> {{ __('db.accounting_health_deep_enable_business_scan') }}</label>
                                    <p>{{ __('db.accounting_health_deep_enable_does_not_run') }}</p><label class="d-block"><input type="checkbox" name="confirmation" value="1" required> {{ __('db.accounting_health_deep_settings_confirmation') }}</label>
                                    <button class="btn btn-outline-primary btn-sm" type="submit">{{ __('db.accounting_health_deep_save_settings') }}</button>
                                </form>
                            @endif
                        @endif
                    </section>
                @endif

                @if($deepScanEnabled)
                    <div class="mt-3">
                        @if($activeDiagnosticScan)
                            <p class="mb-1"><strong id="deep-scan-status">{{ ucfirst($activeDiagnosticScan->status) }}</strong> — <span id="deep-scan-progress">{{ $activeDiagnosticScan->progress }}</span>%</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_consistency', ['status' => $activeDiagnosticScan->consistency_status]) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_data_as_of', ['timestamp' => optional($activeDiagnosticScan->dataset_as_of)->toDateTimeString() ?: __('db.accounting_health_deep_unavailable')]) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_scope', ['scope' => $activeDiagnosticScan->scope === 'warehouse' ? 'Warehouse '.$activeDiagnosticScan->warehouse_id : 'All authorized warehouses']) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_started_at', ['timestamp' => optional($activeDiagnosticScan->started_at)->toDateTimeString() ?: __('db.accounting_health_deep_unavailable')]) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_authoritative_counts', ['rows' => $activeDiagnosticScan->rows_examined, 'findings' => $activeDiagnosticScan->findings_count]) }}</p>
                        @elseif($lastDiagnosticScan)
                            <p class="mb-1"><strong>{{ ucfirst($lastDiagnosticScan->status) }}</strong> — {{ $lastDiagnosticScan->completed_at ?: $lastDiagnosticScan->cancelled_at }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_consistency', ['status' => $lastDiagnosticScan->consistency_status]) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_data_as_of', ['timestamp' => optional($lastDiagnosticScan->dataset_as_of)->toDateTimeString() ?: __('db.accounting_health_deep_unavailable')]) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_scope', ['scope' => $lastDiagnosticScan->scope === 'warehouse' ? 'Warehouse '.$lastDiagnosticScan->warehouse_id : 'All authorized warehouses']) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_started_at', ['timestamp' => optional($lastDiagnosticScan->started_at)->toDateTimeString() ?: __('db.accounting_health_deep_unavailable')]) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_finished_at', ['timestamp' => optional($lastDiagnosticScan->completed_at ?: $lastDiagnosticScan->cancelled_at)->toDateTimeString() ?: __('db.accounting_health_deep_unavailable')]) }}</p>
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_authoritative_counts', ['rows' => $lastDiagnosticScan->rows_examined, 'findings' => $lastDiagnosticScan->findings_count]) }}</p>
                            @if($lastDiagnosticScan->consistency_status === 'stale')<p class="text-warning mb-0">{{ __('db.accounting_health_deep_stale') }}</p>@endif
                            <a class="btn btn-link btn-sm px-0" href="{{ route('accounting.reconciliation.deep-scans.status', ['scan' => $lastDiagnosticScan->scan_key]) }}">{{ __('db.accounting_health_deep_results') }}</a>
                        @else
                            <p class="health-muted mb-0">{{ __('db.accounting_health_deep_none') }}</p>
                        @endif
                    </div>
                    @if($canControlDeepScan && $activeDiagnosticScan && in_array($activeDiagnosticScan->status, ['created', 'queued', 'running']))
                        <button class="btn btn-outline-danger btn-sm" type="button" data-toggle="modal" data-target="#deep-scan-cancel-confirmation">{{ __('db.accounting_health_deep_cancel') }}</button>
                    @elseif($canControlDeepScan && $activeDiagnosticScan && in_array($activeDiagnosticScan->status, ['failed', 'paused']) && $activeDiagnosticScan->consistency_status !== 'incompatible')
                        <form action="{{ route('accounting.reconciliation.deep-scans.resume', ['scan' => $activeDiagnosticScan->scan_key]) }}" method="POST">@csrf
                            <button class="btn btn-outline-primary btn-sm" type="submit">{{ __('db.accounting_health_deep_resume') }}</button>
                        </form>
                    @endif
                @endif
                @if($deepScanEnabled)<small class="text-warning">{{ __('db.accounting_health_deep_warning') }}</small>@endif
            </div>
        </div>

        @if($deepScanEnabled && $canLaunchDeepScan)
        <div class="modal fade" id="deep-scan-confirmation" tabindex="-1" role="dialog" aria-labelledby="deep-scan-confirmation-title" aria-hidden="true">
            <div class="modal-dialog" role="document"><div class="modal-content">
                <div class="modal-header"><h5 id="deep-scan-confirmation-title" class="modal-title">{{ __('db.accounting_health_deep_confirm_title') }}</h5><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('db.Close') }}"><span aria-hidden="true">&times;</span></button></div>
                <form action="{{ route('accounting.reconciliation.deep-scans.start') }}" method="POST">@csrf
                    <div class="modal-body"><p>{{ __('db.accounting_health_deep_confirm_scope') }}</p><p>{{ __('db.accounting_health_deep_confirm_read_only') }}</p><input type="hidden" name="confirm_read_only" value="1"></div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('db.Cancel') }}</button><button type="submit" class="btn btn-primary">{{ __('db.accounting_health_deep_start') }}</button></div>
                </form>
            </div></div>
        </div>
        @endif

        @if($canControlDeepScan && $activeDiagnosticScan && in_array($activeDiagnosticScan->status, ['created', 'queued', 'running']))
        <div class="modal fade" id="deep-scan-cancel-confirmation" tabindex="-1" role="dialog" aria-labelledby="deep-scan-cancel-confirmation-title" aria-hidden="true">
            <div class="modal-dialog" role="document"><div class="modal-content">
                <div class="modal-header"><h5 id="deep-scan-cancel-confirmation-title" class="modal-title">{{ __('db.accounting_health_deep_cancel_confirm_title') }}</h5><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('db.Close') }}"><span aria-hidden="true">&times;</span></button></div>
                <form action="{{ route('accounting.reconciliation.deep-scans.cancel', ['scan' => $activeDiagnosticScan->scan_key]) }}" method="POST">@csrf
                    <div class="modal-body"><p>{{ __('db.accounting_health_deep_cancel_confirm_body') }}</p><input type="hidden" name="confirmation" value="1"></div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('db.Cancel') }}</button><button type="submit" class="btn btn-danger">{{ __('db.accounting_health_deep_cancel') }}</button></div>
                </form>
            </div></div>
        </div>
        @endif

        @endif

        <div class="card accounting-health-hero {{ $overall['level'] }} mb-4">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <span class="health-status-pill {{ $overall['level'] === 'green' ? 'pass' : ($overall['level'] === 'yellow' ? 'warn' : ($overall['level'] === 'red' ? 'fail' : 'not_run')) }}">
                            {{ __('db.' . $overall['label_key']) }}
                        </span>
                        <h2 class="mt-3 mb-2">{{ __('db.' . $overall['message_key']) }}</h2>
                        <p class="health-muted mb-0">
                            {{ __('db.accounting_health_last_completed_check') }}
                            <strong>{{ $certification['checked_at'] ?? __('db.accounting_health_not_run_yet') }}</strong>
                        </p>
                    </div>
                    <div class="col-lg-4 mt-4 mt-lg-0">
                        <div class="row no-gutters text-center">
                            <div class="col-6 border rounded p-2 bg-white"><small>{{ __('db.accounting_health_summary_integrity') }}</small><h4>{{ $health['summary_counts']['integrity'] ?? 0 }}</h4></div>
                            <div class="col-6 border rounded p-2 bg-white"><small>{{ __('db.accounting_health_summary_operational') }}</small><h4>{{ $health['summary_counts']['operational'] ?? 0 }}</h4></div>
                            <div class="col-6 border rounded p-2 bg-white"><small>{{ __('db.accounting_health_summary_setup') }}</small><h4>{{ $health['summary_counts']['setup'] ?? 0 }}</h4></div>
                            <div class="col-6 border rounded p-2 bg-white"><small>{{ __('db.accounting_health_summary_not_run') }}</small><h4>{{ $health['summary_counts']['not_run'] ?? 0 }}</h4></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12 mb-3">
                <h4 class="mb-1">{{ __('db.accounting_health_areas_title') }}</h4>
                <p class="health-muted mb-0">{{ __('db.accounting_health_areas_intro') }}</p>
            </div>

            @foreach($health['groups'] as $group)
                <div class="col-lg-4 mb-3">
                    <div class="card health-group-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h5 class="mb-0">{{ __('db.' . $group['title_key']) }}</h5>
                                <span class="health-status-pill {{ $group['tone'] }}">
                                    {{ __('db.' . $group['display_status_key']) }}
                                </span>
                            </div>
                            <p class="health-muted">{{ __('db.' . $group['description_key']) }}</p>
                            <p class="mb-3">{{ __('db.' . $group['summary_key']) }}</p>

                            @if($group['display_status'] === 'healthy')
                                <p class="text-success font-weight-bold mb-2"><i class="ti ti-circle-check"></i> {{ __('No action needed') }}</p>
                            @else
                                <a href="#issues-requiring-attention" class="btn btn-primary btn-sm mb-2 health-jump-link">
                                    {{ __('Review required actions') }}
                                </a>
                            @endif

                            <button class="btn btn-link btn-sm px-0 health-details-trigger" type="button"
                                data-health-target="{{ $group['details_id'] }}" aria-expanded="false" aria-controls="{{ $group['details_id'] }}">
                                {{ __('db.accounting_health_action_view_details') }}
                            </button>
                            <div id="{{ $group['details_id'] }}" class="collapse health-details-target" data-health-panel>
                                <h6 class="sr-only health-details-heading" data-health-focus tabindex="-1">{{ __('db.accounting_health_details_group_heading', ['group' => __('db.' . $group['title_key'])]) }}</h6>
                                @if(empty($group['checks']))
                                    <div class="health-detail-list">
                                        <p class="mb-0">{{ __('db.accounting_health_details_advanced_disabled') }}</p>
                                    </div>
                                @endif
                                @foreach($group['checks'] as $checkKey => $check)
                                    @php($checkTarget = 'health-check-'.str_replace('_', '-', $checkKey))
                                    <div id="{{ $checkTarget }}" class="health-detail-list health-details-target">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <strong class="health-details-heading" data-health-focus tabindex="-1">{{ __('db.' . $check['label_key']) }}</strong>
                                            <span class="health-status-pill {{ $check['tone'] }}">{{ __('db.' . $check['display_status_key']) }}</span>
                                        </div>
                                        <p class="health-muted mb-1">{{ __('db.' . $check['description_key']) }}</p>
                                        <p class="mb-0">{{ __('db.' . $check['detail_key'], $check['detail_params'] ?? []) }}</p>
                                        @if(isset($check['diagnostic']))
                                            @if($canViewTechnicalDetails)
                                                @if(!empty($check['diagnostic']['sample_records']))
                                                    <pre class="health-technical-output mt-2 mb-0">{{ json_encode($check['diagnostic']['sample_records'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                @else
                                                    <p class="health-muted mt-2 mb-0">{{ __('db.accounting_health_details_evidence_empty') }}</p>
                                                @endif
                                            @else
                                                <p class="health-muted mt-2 mb-0">{{ __('db.accounting_health_details_evidence_restricted') }}</p>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if(!empty($health['issues']))
            <div class="row mb-4">
                <div class="col-12">
                    <div id="issues-requiring-attention" class="card health-issue-card">
                        <div class="card-body">
                            <span id="failed-health-checks" tabindex="-1"></span>
                            <h4 class="mb-3">{{ __('db.accounting_health_issues_title') }}</h4>

                            @foreach($health['issues'] as $issue)
                                <div class="border rounded p-3 mb-3 health-action-card {{ $issue['tone'] }}">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start">
                                        <div class="pr-md-3">
                                            <span class="health-status-pill {{ $issue['tone'] }}">{{ __('db.accounting_health_status_'.($issue['category'] ?? 'manual_review')) }}</span>
                                            <h5 class="mt-2 mb-1">{{ __('db.' . $issue['title_key'], $issue['explanation_params'] ?? []) }}</h5>
                                            @include('backend.accounting.reconciliation.partials.issue_guidance', ['issue' => $issue])
                                        </div>
                                        @if($issue['action_label_key'] && $issue['action_url'] && ($issue['action_url'] !== '#technical-details' || $canViewTechnicalDetails))
                                            @if($issue['action_url'] === '#payment-account-repair-review')
                                                <button type="button" class="btn btn-outline-primary btn-sm mt-3 mt-md-0" data-toggle="modal" data-target="#payment-account-repair-review">
                                                    {{ __('db.' . $issue['action_label_key']) }}
                                                </button>
                                            @elseif(\Illuminate\Support\Str::startsWith($issue['action_url'], '#'))
                                                <a href="{{ $issue['action_url'] }}" class="btn btn-outline-primary btn-sm mt-3 mt-md-0 health-details-trigger" data-health-target="{{ ltrim($issue['action_url'], '#') }}" aria-expanded="false" aria-controls="{{ ltrim($issue['action_url'], '#') }}">
                                                    {{ __('db.' . $issue['action_label_key']) }}
                                                </a>
                                            @else
                                                <a href="{{ $issue['action_url'] }}" class="btn btn-outline-primary btn-sm mt-3 mt-md-0">
                                                    {{ __('db.' . $issue['action_label_key']) }}
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            </div>
        @else
            <div class="alert alert-success mb-4">
                <strong>{{ __('No accounting action is currently required.') }}</strong>
            </div>
        @endif

        @php($orphanCheck = $health['checks']['orphan_journals'] ?? null)
        @if(($orphanCheck['count'] ?? 0) > 0)
            <div id="orphan-journal-review" class="card health-issue-card health-details-target mb-4" data-health-panel>
                <div class="card-body">
                    <span class="health-status-pill warn">{{ __('db.accounting_health_status_manual_review') }}</span>
                    <h4 class="health-details-heading mt-2" data-health-focus tabindex="-1">{{ __('db.accounting_health_orphan_title') }}</h4>
                    <h5>{{ __('db.accounting_health_issue_what_found') }}</h5><p>{{ __('db.accounting_health_orphan_found', ['count' => $orphanCheck['count']]) }}</p>
                    <h5>{{ __('db.accounting_health_issue_why_matters') }}</h5><p>{{ __('db.accounting_health_orphan_risk') }}</p>
                    <h5>{{ __('db.accounting_health_orphan_source_meaning_title') }}</h5><p>{{ __('db.accounting_health_orphan_source_meaning') }}</p>
                    <h5>{{ __('db.accounting_health_orphan_what_to_check') }}</h5><ol><li>{{ __('db.accounting_health_orphan_check_source') }}</li><li>{{ __('db.accounting_health_orphan_check_reversal') }}</li><li>{{ __('db.accounting_health_orphan_check_totals') }}</li><li>{{ __('db.accounting_health_orphan_document_review') }}</li></ol>
                    <h5>{{ __('db.accounting_health_issue_recommended_resolution') }}</h5><p>{{ __('db.accounting_health_orphan_verify') }}</p>
                    <div class="alert alert-warning"><strong>{{ __('db.accounting_health_orphan_do_not_delete') }}</strong></div>
                    <p><strong>{{ __('db.accounting_health_mapping_verify_resolution') }}:</strong> {{ __('db.accounting_health_orphan_verify_resolution') }}</p>
                    @if($canViewTechnicalDetails)
                        @if(!empty($orphanCheck['review_evidence']))
                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead><tr><th>{{ __('db.accounting_health_orphan_journal_reference') }}</th><th>{{ __('db.accounting_health_orphan_journal_date') }}</th><th>{{ __('db.accounting_health_orphan_financial_effect') }}</th><th>{{ __('db.accounting_health_orphan_source_state') }}</th></tr></thead>
                                    <tbody>
                                    @foreach($orphanCheck['review_evidence'] as $sample)
                                        <tr>
                                            <td>{{ $sample['journal_reference'] }}</td><td>{{ $sample['journal_date'] }}</td>
                                            <td>{{ __('db.accounting_health_orphan_debit_credit', ['debit' => number_format($sample['debit_total'], 2), 'credit' => number_format($sample['credit_total'], 2)]) }}<br>{{ $sample['balanced'] ? __('db.accounting_health_orphan_balanced') : __('db.accounting_health_orphan_unbalanced') }}</td>
                                            <td>{{ __('db.accounting_health_orphan_state_'.$sample['source_state']) }} @if($sample['reversal_reference'])<br>{{ __('db.accounting_health_orphan_reversal', ['reference' => $sample['reversal_reference']]) }}@endif</td>
                                        </tr>
                                        <tr><td colspan="4"><strong>{{ __('db.accounting_health_orphan_accounts_affected') }}:</strong> {{ implode(', ', $sample['accounts']) ?: __('db.accounting_health_orphan_none_recorded') }} · <strong>{{ __('db.accounting_health_orphan_created_by') }}:</strong> {{ $sample['created_by'] ?: __('db.accounting_health_orphan_unknown') }}
                                            @if(($sample['repair_path'] ?? null) === 'deterministic')<div class="alert alert-success mt-2"><strong>{{ __('db.accounting_health_status_salepro_can_fix') }}</strong>
                                            @elseif(($sample['repair_path'] ?? null) === 'owner_confirmed')<div class="alert alert-warning mt-2"><strong>{{ __('db.accounting_health_orphan_review_refund') }}</strong><p>{{ __('db.accounting_health_orphan_insufficient_history', ['amount' => number_format($sample['debit_total'], 2)]) }}</p><p>{{ __('db.accounting_health_orphan_owner_question') }}</p>@endif
                                            @if(in_array($sample['repair_path'] ?? null, ['deterministic','owner_confirmed'], true))
                                                @if($guidedRepairEnabled && $canPreviewGuidedRepair)<form method="POST" action="{{ route('accounting.reconciliation.orphan-journal-repairs.preview', $sample['journal_id']) }}">@csrf<input type="hidden" name="idempotency_key" value="orphan-{{ $sample['journal_id'] }}-{{ \Illuminate\Support\Str::uuid() }}">
                                                @if(($sample['repair_path'] ?? null) === 'owner_confirmed')<label><input type="radio" required name="owner_decision" value="cancelled_or_removed"> {{ __('db.accounting_health_orphan_yes_reverse') }}</label><br><label><input type="radio" required name="owner_decision" value="leave_unchanged"> {{ __('db.accounting_health_orphan_no_leave') }}</label><br>@endif<button class="btn btn-primary btn-sm">{{ ($sample['repair_path'] ?? null) === 'deterministic' ? __('db.accounting_health_action_preview_fix') : __('db.accounting_health_action_review_fix') }}</button></form>
                                                @else<p>{{ __('db.accounting_health_mapping_owner_permission_required') }}</p>@endif</div>
                                            @endif
                                            <details class="mt-1"><summary>{{ __('db.accounting_health_mapping_technical_details') }}</summary>{{ __('db.accounting_health_orphan_source_sentence', ['type' => $sample['source_type'], 'id' => $sample['source_id']]) }} · {{ __('db.accounting_health_orphan_technical_contract', ['journal' => $sample['journal_id'], 'contract' => $sample['source_contract'], 'source' => $sample['source_id'], 'warehouse' => $sample['warehouse_id'] ?: 'global']) }}</details>
                                        </td></tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if($orphanCheck['review_samples_capped'])
                                <p class="health-muted">{{ __('db.accounting_health_orphan_capped', ['shown' => count($orphanCheck['review_evidence']), 'count' => $orphanCheck['count']]) }}</p>
                            @endif
                        @else
                            <p class="health-muted">{{ __('db.accounting_health_orphan_empty') }}</p>
                        @endif
                    @else
                        <p class="health-muted">{{ __('db.accounting_health_orphan_restricted') }} {{ __('db.accounting_health_mapping_owner_permission_required') }}</p>
                    @endif
                    <form method="POST" action="{{ route('accounting.reconciliation.run-health-check') }}">@csrf<button class="btn btn-outline-primary" type="submit">{{ __('db.accounting_health_orphan_rerun') }}</button></form>
                </div>
            </div>
        @endif

        @if($canRepairPaymentMappings && (($paymentMappingRepair['missing_count'] ?? 0) > 0 || ($paymentMappingRepair['invalid_count'] ?? 0) > 0))
            <div id="payment-account-mappings-details" class="card health-details-target mb-4" data-health-panel>
                <div class="card-body">
                    <span class="health-status-pill warn">{{ __('db.accounting_health_status_setup_required') }}</span>
                    <h4 class="health-details-heading mt-2" data-health-focus tabindex="-1">{{ __('db.accounting_health_mapping_details_title') }}</h4>
                    <p><strong>{{ __('db.accounting_health_issue_what_found') }}</strong> {{ __('db.accounting_health_mapping_found_text', ['count' => ($paymentMappingRepair['missing_count'] ?? 0) + ($paymentMappingRepair['invalid_count'] ?? 0)]) }}</p>
                    <p><strong>{{ __('db.accounting_health_issue_why_matters') }}</strong> {{ __('db.accounting_health_mapping_why_text') }}</p>
                    <p><strong>{{ __('db.accounting_health_issue_recommended_resolution') }}</strong> {{ __('db.accounting_health_mapping_workspace_intro') }}</p>
                    <a class="btn btn-primary" href="{{ route('accounting.reconciliation.payment-mappings.index') }}">{{ __('db.accounting_health_mapping_resolve_mapping') }}</a>
                </div>
            </div>
        @endif

        <div class="row mb-4">
            <div class="col-12">
                @include('backend.accounting.reconciliation.partials.recent_activity')
            </div>
        </div>

        @if($canViewTechnicalDetails)
            <div id="technical-details" class="collapse {{ $technicalExpanded ? 'show' : '' }} health-details-target" data-health-panel>
              <div class="card mb-4">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <h4 class="mb-1 health-details-heading" data-health-focus tabindex="-1">{{ __('db.accounting_health_technical_details_title') }}</h4>
                        <p class="health-muted mb-0">{{ __('db.accounting_health_technical_details_intro') }}</p>
                    </div>
                    <a class="btn btn-outline-secondary mt-3 mt-md-0" href="{{ route('accounting.reconciliation.index') }}">
                        {{ __('Return to accounting review') }}
                    </a>
                </div>
                <div>
                    <div class="card-body">
                        @if(is_array($lastHealthError))
                            <div class="alert alert-warning" role="status">
                                <h5 class="mb-2">{{ __('Latest health-check diagnostic') }}</h5>
                                <dl class="row mb-0">
                                    <dt class="col-sm-3">{{ __('Area') }}</dt><dd class="col-sm-9">{{ $lastHealthError['area'] }}</dd>
                                    <dt class="col-sm-3">{{ __('Status') }}</dt><dd class="col-sm-9">{{ $lastHealthError['status'] }}</dd>
                                    <dt class="col-sm-3">{{ __('Finding') }}</dt><dd class="col-sm-9">{{ $lastHealthError['finding'] }}</dd>
                                    <dt class="col-sm-3">{{ __('Count') }}</dt><dd class="col-sm-9">{{ $lastHealthError['count'] }}</dd>
                                    <dt class="col-sm-3">{{ __('Last execution') }}</dt><dd class="col-sm-9">{{ $lastHealthError['checked_at'] }}</dd>
                                </dl>
                            </div>
                        @endif
                        @if($certification)
                            <h5>{{ __('db.accounting_health_certification_output_title') }}</h5>
                            <p class="health-muted">
                                {{ __('db.accounting_health_certification_output_intro') }}
                                <code>accounting:health-quick</code>
                            </p>
                            @if(filled($certification['output'] ?? null))
                                <pre class="health-technical-output">{{ $certification['output'] }}</pre>
                            @else
                                <div class="alert alert-light border mb-0">
                                    {{ __('db.accounting_health_certification_output_unavailable') }}
                                </div>
                            @endif
                        @else
                            <div class="alert alert-info">
                                {{ __('db.accounting_health_certification_output_empty') }}
                            </div>
                        @endif

                        @if($advancedHealthEnabled)
                        <h5 class="mt-4">{{ __('db.accounting_health_group_historical_integrity') }}</h5>
                        <p class="health-muted">{{ __('db.accounting_health_guidance') }}</p>
                        @foreach(collect($health['checks'])->filter(fn ($check) => isset($check['diagnostic'])) as $check)
                            <div class="border rounded p-3 mb-2">
                                <strong>{{ __('db.' . $check['label_key']) }}</strong>
                                <div>{{ $check['diagnostic']['status'] }} · {{ $check['diagnostic']['affected_count'] }} affected</div>
                                @if(!empty($check['diagnostic']['sample_records']))
                                    <pre class="health-technical-output mb-0">{{ json_encode($check['diagnostic']['sample_records'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                @endif
                            </div>
                        @endforeach
                        @endif

                        <hr>

                        <h5 class="mb-3">{{ __('db.accounting_health_reconciliation_queue_title') }}</h5>
                        <div class="row mb-4">
                            <div class="col-md-3 mb-3">
                                <div class="card border-primary text-center">
                                    <div class="card-body">
                                        <h6>{{ __('db.accounting_health_total_queued') }}</h6>
                                        <h3>{{ $stats['total'] }}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="card border-success text-center">
                                    <div class="card-body">
                                        <h6>{{ __('db.accounting_health_queue_status_posted') }}</h6>
                                        <h3>{{ $stats['posted'] }}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="card border-warning text-center">
                                    <div class="card-body">
                                        <h6>{{ __('db.accounting_health_queue_status_pending') }}</h6>
                                        <h3>{{ $stats['pending'] }}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="card border-danger text-center">
                                    <div class="card-body">
                                        <h6>{{ __('db.accounting_health_queue_status_failed') }}</h6>
                                        <h3>{{ $stats['failed'] }}</h3>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="accounting-reconciliation-table" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('db.accounting_health_table_source') }}</th>
                                        <th>{{ __('db.accounting_health_table_id') }}</th>
                                        <th>{{ __('db.accounting_health_table_status') }}</th>
                                        <th>{{ __('db.accounting_health_table_attempts') }}</th>
                                        <th>{{ __('db.accounting_health_table_last_error') }}</th>
                                        <th>{{ __('db.accounting_health_table_last_attempt') }}</th>
                                        <th>{{ __('db.action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($queue as $q)
                                        <tr>
                                            <td>{{ class_basename($q->source_type) }}</td>
                                            <td>{{ $q->source_id }}</td>
                                            <td>
                                                @if($q->status == 'posted')
                                                    <div class="badge badge-success">{{ __('db.accounting_health_queue_status_posted') }}</div>
                                                @elseif($q->status == 'failed')
                                                    <div class="badge badge-danger">{{ __('db.accounting_health_queue_status_failed') }}</div>
                                                @elseif($q->status == 'reversed')
                                                    <div class="badge badge-secondary">{{ __('db.accounting_health_queue_status_reversed') }}</div>
                                                @else
                                                    <div class="badge badge-warning">{{ __('db.accounting_health_queue_status_pending') }}</div>
                                                @endif
                                            </td>
                                            <td>{{ $q->attempts }}</td>
                                            <td>{{ \Illuminate\Support\Str::limit($q->last_error, 50) }}</td>
                                            <td>{{ $q->last_attempt_at }}</td>
                                            <td>
                                                @if($q->status == 'failed' || $q->status == 'pending')
                                                    <form action="{{ route('accounting.reconciliation.retry', $q->id) }}" method="POST" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-primary">{{ __('db.accounting_health_action_retry_transaction') }}</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">
                            {{ $queue->appends(['technical' => 1])->fragment('technical-details')->links() }}
                        </div>
                    </div>
                </div>
              </div>
            </div>
        @endif
    </div>
</section>
@endsection

@push('scripts')
<script>
    (function ($) {
        if (!$) return;
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var navigationStatus = document.getElementById('health-navigation-status');

        function setTriggerState(panel, expanded) {
            document.querySelectorAll('[data-health-target="' + panel.id + '"]').forEach(function (trigger) {
                trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                if (trigger.dataset.showLabel && trigger.dataset.hideLabel) {
                    trigger.textContent = expanded ? trigger.dataset.hideLabel : trigger.dataset.showLabel;
                }
            });
        }

        function openCollapse(panel) {
            return new Promise(function (resolve) {
                if (panel.classList.contains('show')) {
                    setTriggerState(panel, true);
                    resolve();
                    return;
                }
                var settled = false;
                function done() {
                    if (settled) return;
                    settled = true;
                    setTriggerState(panel, true);
                    resolve();
                }
                $(panel).one('shown.bs.collapse.accountingHealthNavigation', done).collapse('show');
                window.setTimeout(done, 700);
            });
        }

        function failNavigation() {
            if (!navigationStatus) return;
            navigationStatus.textContent = @json(__('db.accounting_health_details_navigation_failed'));
            navigationStatus.classList.remove('d-none');
            navigationStatus.focus();
        }

        function finishNavigation(target, updateHash) {
            if (navigationStatus) navigationStatus.classList.add('d-none');
            if (updateHash && window.location.hash !== '#' + target.id) {
                window.history.pushState(null, '', '#' + target.id);
            }
            var focusTarget = target.matches('[data-health-focus]')
                ? target
                : target.querySelector('[data-health-focus]') || target;
            target.classList.remove('health-anchor-highlight');
            void target.offsetWidth;
            target.classList.add('health-anchor-highlight');
            target.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
            window.setTimeout(function () {
                if (!focusTarget.hasAttribute('tabindex')) focusTarget.setAttribute('tabindex', '-1');
                focusTarget.focus({ preventScroll: true });
            }, reducedMotion ? 0 : 450);
        }

        function revealDetails(targetId, updateHash) {
            var target = document.getElementById(targetId);
            if (!target) {
                failNavigation();
                return Promise.resolve(false);
            }
            var collapses = Array.prototype.slice.call(target.closest('.collapse') ? target.parentElement ? $(target).parents('.collapse').get().reverse() : [] : []);
            if (target.classList.contains('collapse')) collapses.push(target);
            return collapses.reduce(function (promise, panel) {
                return promise.then(function () { return openCollapse(panel); });
            }, Promise.resolve()).then(function () {
                finishNavigation(target, updateHash);
                return true;
            });
        }

        $(document).off('.accountingHealthNavigation')
            .on('click.accountingHealthNavigation', '[data-health-target]', function (event) {
                var targetId = this.getAttribute('data-health-target');
                var target = document.getElementById(targetId);
                event.preventDefault();
                if (this.getAttribute('data-health-toggle') === 'true' && target && target.classList.contains('show')) {
                    $(target).collapse('hide');
                    return;
                }
                revealDetails(targetId, true);
            })
            .on('shown.bs.collapse.accountingHealthNavigation hidden.bs.collapse.accountingHealthNavigation', '.collapse[data-health-panel]', function (event) {
                setTriggerState(event.currentTarget, event.type === 'shown');
            });

        window.AccountingHealthDetailsNavigation = { reveal: revealDetails };
        function revealInitialHash() {
            if (!window.location.hash) return;
            if (!$.fn || typeof $.fn.collapse !== 'function') {
                window.setTimeout(revealInitialHash, 50);
                return;
            }
            revealDetails(decodeURIComponent(window.location.hash.slice(1)), false);
        }
        if (document.readyState === 'complete') {
            revealInitialHash();
        } else {
            window.addEventListener('load', revealInitialHash, { once: true });
        }
        $(function () { window.setTimeout(revealInitialHash, 0); });
        window.addEventListener('hashchange', revealInitialHash);
    }(window.jQuery));

    (function () {
        var card = document.getElementById('deep-scan-card');
        if (!card || !card.dataset.statusUrl) return;
        var terminal = ['completed', 'cancelled', 'failed'];
        function poll() {
            fetch(card.dataset.statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { if (!response.ok) throw new Error('status'); return response.json(); })
                .then(function (scan) {
                    var status = document.getElementById('deep-scan-status');
                    var progress = document.getElementById('deep-scan-progress');
                    if (status) status.textContent = scan.status.charAt(0).toUpperCase() + scan.status.slice(1);
                    if (progress) progress.textContent = scan.progress;
                    if (terminal.indexOf(scan.status) === -1) window.setTimeout(poll, 3000);
                }).catch(function () { window.setTimeout(poll, 10000); });
        }
        var initialStatus = document.getElementById('deep-scan-status');
        if (initialStatus && terminal.indexOf(initialStatus.textContent.toLowerCase()) === -1) window.setTimeout(poll, 3000);
    }());

    (function () {
        var token = document.querySelector('meta[name="csrf-token"]');
        function submitGuided(event) {
            event.preventDefault();
            var form = event.currentTarget;
            if (!form.reportValidity()) return;
            var output = form.parentElement.querySelector('.guided-repair-result');
            var button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            fetch(form.dataset.endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
                body: new FormData(form)
            }).then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {})[0] || 'The guided repair request was rejected.');
                    return body;
                });
            }).then(function (body) {
                output.className = 'guided-repair-result alert alert-success mt-2';
                output.textContent = body.status === 'previewed'
                    ? @json(__('db.accounting_health_mapping_previewed')) + ' ' + (body.plan_key || '') + ' · ' + (body.plan_fingerprint || '')
                    : 'Plan ' + (body.plan_key || '') + ': ' + body.status + (body.verification_status ? ' · verification ' + body.verification_status : '');
                if (body.status !== 'previewed') window.setTimeout(function () { window.location.reload(); }, 1200);
            }).catch(function (error) {
                output.className = 'guided-repair-result alert alert-danger mt-2';
                output.textContent = String(error.message || error);
                button.disabled = false;
            });
        }
        document.querySelectorAll('.guided-mapping-preview, .guided-plan-action').forEach(function (form) {
            form.addEventListener('submit', submitGuided);
        });
    }());
</script>
@endpush
