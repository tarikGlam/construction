<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AccountingSyncQueue;
use App\Services\AccountingHealthService;
use App\Services\AccountingService;
use App\Services\PaymentAccountMappingRepairService;
use App\Services\AccountingJournalRetryService;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Returns;
use App\Models\ReturnPurchase;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payroll;
use App\Models\MoneyTransfer;
use App\Models\AccountingDiagnosticScan;
use App\Jobs\ProcessAccountingDiagnosticScan;
use App\Jobs\AccountingQueueReadinessProbe;
use App\Exceptions\HealthCheckExecutionException;
use App\Services\AccountingDiagnosticDeepScanService;
use App\Services\AccountingGuidedRepairPlanService;
use App\Models\AccountingRepairPlan;
use App\Models\AccountingRepairAuditEvent;
use App\Models\Account;
use App\Models\AccountingConfig;
use App\Services\WarehouseAccessService;
use App\Services\DeepScanReadinessService;
use DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AccountingReconciliationController extends Controller
{
    protected $accountingService;

    public function __construct(AccountingService $accountingService)
    {
        $this->accountingService = $accountingService;
    }

    private const HEALTH_ACCESS_PERMISSIONS = [
        'account-index',
        'chart-of-accounts-manage',
        'semantic-account-mappings-manage',
        'money-transfer',
        'balance-sheet',
        'account-statement',
    ];

    private const TECHNICAL_ACCESS_PERMISSIONS = [
        'chart-of-accounts-manage',
        'semantic-account-mappings-manage',
    ];

    private const PAYMENT_MAPPING_REPAIR_PERMISSION = 'semantic-account-mappings-manage';
    private const SCAN_LAUNCH_PERMISSION = 'accounting-health-scan-launch';
    private const SCAN_CONTROL_PERMISSION = 'accounting-health-scan-control';
    private const SCAN_SETTINGS_PERMISSION = 'accounting-health-settings-manage';

    public function index(Request $request, AccountingHealthService $healthService, PaymentAccountMappingRepairService $repairService)
    {
        if (!$this->userHasAnyAccountingPermission(self::HEALTH_ACCESS_PERMISSIONS)) {
            return redirect('/')->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        }

        $lastCertification = session('accounting_health.last_certification');
        $lastHealth = session('accounting_health.last_health');
        $health = is_array($lastHealth)
            ? $lastHealth
            : $healthService->build(is_array($lastCertification) ? $lastCertification : null);
        $technicalExpanded = $request->boolean('technical');
        $canViewTechnicalDetails = $this->userHasAnyAccountingPermission(self::TECHNICAL_ACCESS_PERMISSIONS);
        $lastHealthError = $canViewTechnicalDetails ? session('accounting_health.last_error') : null;
        $healthRunNotice = session('accounting_health.run_notice');
        if (is_array($healthRunNotice) && !$canViewTechnicalDetails) unset($healthRunNotice['technical']);
        $deepSetupExpanded = $request->boolean('deep_setup');
        $warehouseAccess = app(WarehouseAccessService::class);
        $canManageGlobalMappings = $warehouseAccess->classification() === WarehouseAccessService::GLOBAL_OPERATIONAL;
        $canReviewPaymentMappings = $canManageGlobalMappings
            && $this->userHasAnyAccountingPermission([self::PAYMENT_MAPPING_REPAIR_PERMISSION]);
        $canPreviewGuidedRepair = $canManageGlobalMappings
            && $this->hasReservedOrPermission(AccountingGuidedRepairPlanService::PREVIEW_PERMISSION);
        $canApproveGuidedRepair = $canManageGlobalMappings
            && $this->hasReservedOrPermission(AccountingGuidedRepairPlanService::APPROVE_PERMISSION);
        $canExecuteGuidedRepair = $canManageGlobalMappings
            && $this->hasReservedOrPermission(AccountingGuidedRepairPlanService::EXECUTE_PERMISSION);
        $guidedRepairEnabled = (bool) config('accounting.guided_repair_enabled', false);
        $canRepairPaymentMappings = $canReviewPaymentMappings || $canPreviewGuidedRepair;
        $paymentMappingRepair = $canRepairPaymentMappings ? $repairService->inspect() : null;
        $pendingPaymentMappingPlans = collect();
        if ($guidedRepairEnabled && ($canApproveGuidedRepair || $canExecuteGuidedRepair)
            && Schema::hasTable('accounting_repair_plans')) {
            $pendingPaymentMappingPlans = AccountingRepairPlan::query()
                ->where('repair_key', 'create_missing_payment_account_mapping')
                ->whereIn('status', ['previewed', 'approved'])
                ->latest('id')->limit(20)->get();
        }
        $queue = $canViewTechnicalDetails
            ? AccountingSyncQueue::orderBy('updated_at', 'desc')->paginate(50)
            : null;
        $stats = $canViewTechnicalDetails ? [
            'total' => AccountingSyncQueue::count(),
            'failed' => AccountingSyncQueue::where('status', 'failed')->count(),
            'pending' => AccountingSyncQueue::where('status', 'pending')->count(),
            'posted' => AccountingSyncQueue::where('status', 'posted')->count(),
        ] : [];
        $canLaunchDeepScan = $this->hasReservedOrPermission(self::SCAN_LAUNCH_PERMISSION);
        $canControlDeepScan = $this->hasReservedOrPermission(self::SCAN_CONTROL_PERMISSION);
        $canConfigureDeepScan = $this->hasReservedOrPermission(self::SCAN_SETTINGS_PERMISSION);
        $deepScanAvailability = $this->deepScanAvailability();
        $advancedHealthEnabled = $deepScanAvailability['advanced_enabled'];
        $deepScanEnabled = $deepScanAvailability['available'];
        $activeDiagnosticScan = null;
        $lastDiagnosticScan = null;
        if ($deepScanEnabled && Schema::hasTable('accounting_diagnostic_scans')) {
            $scanQuery = AccountingDiagnosticScan::query();
            if ($warehouseAccess->isRestricted()) {
                $scanQuery->where('scope', 'warehouse')->where('warehouse_id', $warehouseAccess->warehouseId());
            }
            $activeDiagnosticScan = (clone $scanQuery)->whereIn('status', ['created', 'queued', 'running', 'paused', 'failed'])->latest()->first();
            $lastDiagnosticScan = (clone $scanQuery)->whereIn('status', ['completed', 'completed_with_warnings', 'cancelled'])
                ->latest('updated_at')->first();
        }

        return view('backend.accounting.reconciliation.index', compact(
            'queue',
            'stats',
            'health',
            'technicalExpanded',
            'canViewTechnicalDetails',
            'lastHealthError',
            'healthRunNotice',
            'deepSetupExpanded',
            'canRepairPaymentMappings',
            'paymentMappingRepair',
            'canReviewPaymentMappings',
            'canPreviewGuidedRepair',
            'canApproveGuidedRepair',
            'canExecuteGuidedRepair',
            'guidedRepairEnabled',
            'pendingPaymentMappingPlans',
            'advancedHealthEnabled',
            'deepScanEnabled',
            'deepScanAvailability',
            'canLaunchDeepScan',
            'canControlDeepScan',
            'canConfigureDeepScan',
            'activeDiagnosticScan',
            'lastDiagnosticScan'
        ));
    }

    public function startDeepScan(Request $request, AccountingDiagnosticDeepScanService $service)
    {
        $this->authorizeHealthAccess();
        $this->authorizeDeepScan(self::SCAN_LAUNCH_PERMISSION);
        $request->validate(['confirm_read_only' => ['required', 'accepted']]);
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;
        $originalWarehouseId = $request->attributes->get('requested_warehouse_id');
        if ($originalWarehouseId !== null && (int) $originalWarehouseId !== $warehouseId) abort(403);
        $scan = $service->start($warehouseId);
        $shouldDispatch = $scan->wasRecentlyCreated;
        $scan = $service->markQueued($scan);
        if ($shouldDispatch) ProcessAccountingDiagnosticScan::dispatch($scan->id);
        return redirect()->route('accounting.reconciliation.index')->with('message', __('db.accounting_health_deep_started'));
    }

    public function deepScanStatus(string $scan, AccountingDiagnosticDeepScanService $service)
    {
        $this->authorizeHealthAccess();
        $this->authorizeDeepScan(self::SCAN_LAUNCH_PERMISSION);
        $scan = AccountingDiagnosticScan::where('scan_key', $scan)->firstOrFail();
        $service->authorize($scan);
        $results = $scan->results;
        $progressDetails = $scan->progress_details;
        if (!$this->canViewDiagnosticTechnicalEvidence()) {
            $results = $this->redactTechnicalEvidence($results ?: []);
            $progressDetails = null;
        }
        return response()->json(['scan_key' => $scan->scan_key, 'status' => $scan->status,
            'consistency_status' => $scan->consistency_status, 'progress' => $scan->progress,
            'current_check' => $scan->current_check, 'progress_details' => $progressDetails,
            'rows_examined' => $scan->rows_examined, 'findings_count' => $scan->findings_count,
            'dataset_as_of' => optional($scan->dataset_as_of)->toIso8601String(), 'results' => $results,
            'failure_summary' => $scan->failure_summary]);
    }

    public function previewRepair(Request $request, AccountingSyncQueue $queue, AccountingGuidedRepairPlanService $service)
    {
        $validated = $request->validate(['idempotency_key' => ['required', 'string', 'max:100'],
            'backup_confirmed' => ['sometimes', 'boolean']]);
        $plan = $service->previewMissingAccountingPost($queue, $validated['idempotency_key'],
            (bool) ($validated['backup_confirmed'] ?? false));
        return response()->json(['plan_key' => $plan->plan_key, 'status' => $plan->status,
            'repair_key' => $plan->repair_key, 'proposed_mutations' => $plan->proposed_mutations,
            'expected_effect' => $plan->expected_effect, 'expires_at' => $plan->expires_at->toIso8601String()], 201);
    }

    public function previewPaymentMappingRepair(Request $request, string $account, AccountingGuidedRepairPlanService $service)
    {
        $this->authorizeGuidedRepairAccess(AccountingGuidedRepairPlanService::PREVIEW_PERMISSION);
        app(WarehouseAccessService::class)->authorizeWarehouse(null);
        $account = Account::whereKey((int) $account)->firstOrFail();
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'backup_confirmed' => ['sometimes', 'boolean'],
            'resolution' => ['sometimes', 'in:create,existing,owner_classification'],
            'ledger_account_id' => ['nullable', 'integer'],
            'owner_classification' => ['nullable', 'in:cash,owner_investment'],
        ]);
        $plan = $service->previewPaymentAccountMapping(
            $account,
            $validated['idempotency_key'],
            (bool) ($validated['backup_confirmed'] ?? false),
            ($validated['resolution'] ?? 'create') === 'existing' ? (int) ($validated['ledger_account_id'] ?? 0) : null,
            ($validated['resolution'] ?? 'create') === 'owner_classification' ? ($validated['owner_classification'] ?? null) : null
        );

        if (!$request->expectsJson()) {
            return redirect()->route('accounting.reconciliation.payment-mappings.index', ['plan' => $plan->plan_key])
                ->with('message', __('db.accounting_health_mapping_previewed'));
        }

        return response()->json([
            'plan_key' => $plan->plan_key,
            'status' => $plan->status,
            'proposed_mutations' => $plan->proposed_mutations,
            'expected_effect' => $plan->expected_effect,
            'plan_fingerprint' => $plan->preconditions['immutable_payload_hash'] ?? null,
            'expires_at' => $plan->expires_at->toIso8601String(),
            'approval_url' => route('accounting.reconciliation.repairs.approve', $plan),
            'execution_url' => route('accounting.reconciliation.repairs.execute', $plan),
        ], 201);
    }

    public function previewOrphanJournalRepair(Request $request, string $journal, AccountingGuidedRepairPlanService $service)
    {
        $this->authorizeGuidedRepairAccess(AccountingGuidedRepairPlanService::PREVIEW_PERMISSION);
        $entry = \App\Models\JournalEntry::whereKey((int) $journal)->firstOrFail();
        app(WarehouseAccessService::class)->authorizeWarehouse($entry->warehouse_id ? (int) $entry->warehouse_id : null);
        $validated = $request->validate(['idempotency_key' => ['required', 'string', 'max:100'],
            'owner_decision' => ['nullable', 'in:cancelled_or_removed,leave_unchanged']]);
        if (($validated['owner_decision'] ?? null) === 'leave_unchanged') {
            app(\App\Services\AccountingSourceLifecycleService::class)->record(
                (string) $entry->source_type, (int) $entry->source_id, 'owner_review_leave_unchanged', 'completed',
                $entry, null, null, null, auth()->id()
            );
            return back()->with('message', __('db.accounting_health_orphan_left_unchanged'));
        }
        $plan = $service->previewOrphanJournal($entry, $validated['idempotency_key'],
            ($validated['owner_decision'] ?? null) === 'cancelled_or_removed');
        return redirect()->route('accounting.reconciliation.index', ['plan' => $plan->plan_key, 'failed_checks' => 1])
            ->with('message', __('db.accounting_health_orphan_previewed'));
    }

    public function approveRepair(Request $request, string $plan, AccountingGuidedRepairPlanService $service)
    {
        $this->authorizeGuidedRepairAccess(AccountingGuidedRepairPlanService::APPROVE_PERMISSION);
        $plan = AccountingRepairPlan::where('plan_key', $plan)->firstOrFail();
        $validated = $request->validate(['backup_confirmed' => ['required', 'accepted']]);
        $plan = $service->approve($plan, (bool) $validated['backup_confirmed']);
        if (!$request->expectsJson()) {
            $route = in_array($plan->repair_key, ['create_missing_payment_account_mapping', 'map_existing_payment_account', 'classify_owner_payment_account'], true)
                ? 'accounting.reconciliation.payment-mappings.index' : 'accounting.reconciliation.index';
            return redirect()->route($route, ['plan' => $plan->plan_key])
                ->with('message', __('db.accounting_health_mapping_approved'));
        }
        return response()->json(['plan_key' => $plan->plan_key, 'status' => $plan->status, 'approved_at' => $plan->approved_at->toIso8601String()]);
    }

    public function executeRepair(Request $request, string $plan, AccountingGuidedRepairPlanService $service)
    {
        $this->authorizeGuidedRepairAccess(AccountingGuidedRepairPlanService::EXECUTE_PERMISSION);
        $plan = AccountingRepairPlan::where('plan_key', $plan)->firstOrFail();
        $plan = $service->execute($plan);
        if (!$request->expectsJson()) {
            $route = in_array($plan->repair_key, ['create_missing_payment_account_mapping', 'map_existing_payment_account', 'classify_owner_payment_account'], true)
                ? 'accounting.reconciliation.payment-mappings.index' : 'accounting.reconciliation.index';
            return redirect()->route($route, ['plan' => $plan->plan_key])
                ->with('message', __('db.accounting_health_mapping_verified'));
        }
        return response()->json(['plan_key' => $plan->plan_key, 'status' => $plan->status,
            'verification_status' => $plan->verification_status, 'result' => $plan->result]);
    }

    public function repairAudit(string $plan, AccountingGuidedRepairPlanService $service)
    {
        $this->authorizeGuidedRepairAccess(AccountingGuidedRepairPlanService::AUDIT_PERMISSION);
        $plan = AccountingRepairPlan::where('plan_key', $plan)->firstOrFail();
        $service->authorizeAudit($plan);
        return response()->json(['plan_key' => $plan->plan_key, 'events' => AccountingRepairAuditEvent::where('repair_plan_id', $plan->id)
            ->orderBy('id')->get(['event_key', 'event_type', 'actor_id', 'evidence', 'previous_event_hash', 'event_hash', 'occurred_at'])]);
    }

    public function cancelDeepScan(Request $request, string $scan, AccountingDiagnosticDeepScanService $service)
    {
        $this->authorizeHealthAccess();
        $this->authorizeDeepScan(self::SCAN_CONTROL_PERMISSION);
        $scan = AccountingDiagnosticScan::where('scan_key', $scan)->firstOrFail();
        $service->authorize($scan);
        $request->validate(['confirmation' => ['required', 'accepted']]);
        $service->cancel($scan);
        return redirect()->route('accounting.reconciliation.index')->with('message', __('db.accounting_health_deep_cancelled'));
    }

    public function resumeDeepScan(string $scan, AccountingDiagnosticDeepScanService $service)
    {
        $this->authorizeHealthAccess();
        $this->authorizeDeepScan(self::SCAN_CONTROL_PERMISSION);
        $scan = AccountingDiagnosticScan::where('scan_key', $scan)->firstOrFail();
        $scan = $service->resume($scan);
        ProcessAccountingDiagnosticScan::dispatch($scan->id);
        return redirect()->route('accounting.reconciliation.index')->with('message', __('db.accounting_health_deep_resumed'));
    }

    private function authorizeHealthAccess(): void
    {
        abort_unless($this->userHasAnyAccountingPermission(self::HEALTH_ACCESS_PERMISSIONS), 403);
    }

    private function authorizeDeepScan(string $permission): void
    {
        $user = Auth::user();
        abort_unless($user && ((int) $user->role_id <= 2 || $user->hasPermissionTo($permission)), 403);
        $availability = $this->deepScanAvailability();
        abort_unless($availability['available'], 409, implode(' ', $availability['reasons']));
    }

    private function deepScanAvailability(): array
    {
        return app(DeepScanReadinessService::class)->assess();
    }

    public function testDeepScanWorker()
    {
        $this->authorizeHealthAccess();
        abort_unless($this->hasReservedOrPermission(self::SCAN_SETTINGS_PERMISSION), 403);
        app(WarehouseAccessService::class)->authorizeWarehouse(null);
        $readiness = $this->deepScanAvailability();
        abort_unless($readiness['queue_configured'], 409);
        AccountingQueueReadinessProbe::dispatch();

        return redirect()->route('accounting.reconciliation.index', ['deep_setup' => 1])
            ->with('message', __('db.accounting_health_deep_worker_probe_queued'));
    }

    public function paymentMappingWorkspace(Request $request, PaymentAccountMappingRepairService $repairService)
    {
        $this->authorizeHealthAccess();
        app(WarehouseAccessService::class)->authorizeWarehouse(null);
        $canViewTechnicalDetails = $this->canViewDiagnosticTechnicalEvidence();
        $canPreviewGuidedRepair = $this->hasReservedOrPermission(AccountingGuidedRepairPlanService::PREVIEW_PERMISSION);
        $canApproveGuidedRepair = $this->hasReservedOrPermission(AccountingGuidedRepairPlanService::APPROVE_PERMISSION);
        $canExecuteGuidedRepair = $this->hasReservedOrPermission(AccountingGuidedRepairPlanService::EXECUTE_PERMISSION);
        $guidedRepairEnabled = (bool) config('accounting.guided_repair_enabled', false);
        $inspection = $repairService->inspect(50, true);
        $compatibleLedgers = $repairService->compatibleLedgerAccounts();
        $plans = Schema::hasTable('accounting_repair_plans') ? AccountingRepairPlan::query()
            ->whereIn('repair_key', ['create_missing_payment_account_mapping', 'map_existing_payment_account', 'classify_owner_payment_account'])
            ->latest('id')->limit(30)->get() : collect();

        return view('backend.accounting.reconciliation.payment_mappings', compact('inspection', 'compatibleLedgers',
            'plans', 'canViewTechnicalDetails', 'canPreviewGuidedRepair', 'canApproveGuidedRepair',
            'canExecuteGuidedRepair', 'guidedRepairEnabled'));
    }

    public function updateDiagnosticSettings(Request $request)
    {
        $this->authorizeHealthAccess();
        abort_unless($this->hasReservedOrPermission(self::SCAN_SETTINGS_PERMISSION), 403);
        app(WarehouseAccessService::class)->authorizeWarehouse(null);
        abort_unless(
            Schema::hasTable('accounting_configs')
                && Schema::hasColumns('accounting_configs', ['advanced_diagnostics_enabled', 'deep_scan_enabled']),
            409
        );

        $data = $request->validate([
            'advanced_diagnostics_enabled' => ['required', 'boolean'],
            'deep_scan_enabled' => ['required', 'boolean'],
            'confirmation' => ['required', 'accepted'],
        ]);
        abort_if($data['advanced_diagnostics_enabled'] && !config('accounting.health_advanced_enabled', false), 409);
        abort_if($data['deep_scan_enabled'] && !config('accounting.deep_scan_enabled', false), 409);
        abort_if($data['deep_scan_enabled'] && !$data['advanced_diagnostics_enabled'], 422);

        DB::transaction(function () use ($data) {
            $config = AccountingConfig::whereKey(1)->lockForUpdate()->firstOrFail();
            $before = [
                'advanced_diagnostics_enabled' => (bool) $config->advanced_diagnostics_enabled,
                'deep_scan_enabled' => (bool) $config->deep_scan_enabled,
            ];
            $after = [
                'advanced_diagnostics_enabled' => (bool) $data['advanced_diagnostics_enabled'],
                'deep_scan_enabled' => (bool) $data['deep_scan_enabled'],
            ];
            if ($before === $after) return;
            $config->update($after);
            $previous = DB::table('accounting_health_setting_audits')->latest('id')->first();
            $occurredAt = now();
            $eventKey = (string) Str::uuid();
            $payload = ['event_key' => $eventKey, 'actor_id' => auth()->id(), 'before_state' => $before,
                'after_state' => $after, 'previous_event_hash' => $previous?->event_hash,
                'occurred_at' => $occurredAt->toIso8601String()];
            DB::table('accounting_health_setting_audits')->insert([
                'event_key' => $eventKey, 'actor_id' => auth()->id(),
                'before_state' => json_encode($before), 'after_state' => json_encode($after),
                'previous_event_hash' => $previous?->event_hash,
                'event_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
                'occurred_at' => $occurredAt,
            ]);
        });

        return redirect()->route('accounting.reconciliation.index', ['diagnostics' => 1])
            ->with('message', __('db.accounting_health_deep_settings_saved'));
    }

    private function canViewDiagnosticTechnicalEvidence(): bool
    {
        $user = Auth::user();
        return $user && ((int) $user->role_id <= 2 || $user->hasPermissionTo('accounting-health-technical-view')
            || $this->userHasAnyAccountingPermission(self::TECHNICAL_ACCESS_PERMISSIONS));
    }

    private function hasReservedOrPermission(string $permission): bool
    {
        $user = Auth::user();
        return $user && ((int) $user->role_id <= 2 || $user->hasPermissionTo($permission));
    }

    private function authorizeGuidedRepairAccess(string $permission): void
    {
        $access = app(WarehouseAccessService::class);
        $user = $access->user();
        abort_if(!$user || $access->isPortalIdentity()
            || $access->classification() === WarehouseAccessService::INVALID_OPERATIONAL, 403);
        abort_unless((int) $user->role_id <= 2 || $user->hasPermissionTo($permission), 403);
    }

    private function redactTechnicalEvidence(array $results): array
    {
        foreach ($results as &$result) {
            if (!is_array($result)) continue;
            unset($result['technical_evidence'], $result['checkpoints']);
            if (isset($result['sample_records'])) {
                $result['sample_records'] = array_map(fn ($sample) => array_intersect_key((array) $sample,
                    array_flip(['id', 'reference', 'warehouse_id', 'classification', 'reason'])), $result['sample_records']);
            }
        }
        return $results;
    }

    public function runHealthCheck(AccountingHealthService $healthService)
    {
        if (!$this->userHasAnyAccountingPermission(self::HEALTH_ACCESS_PERMISSIONS)) {
            return redirect('/')->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        }

        try {
            // The web action is intentionally bounded and read-only. Release-level
            // certification remains available through accounting:certify.
            $result = $healthService->runBoundedWebCheck();
            if ($result['completed'] ?? false) {
                $storedResult = $result;
                unset($storedResult['health']);
                session()->put('accounting_health.last_certification', $storedResult);
                session()->forget('accounting_health.last_error');
                session()->forget('accounting_health.run_notice');
            }

            $customerHealth = $result['health'] ?? null;
            if (!is_array($customerHealth)) {
                $notice = $this->rememberSafeHealthFailure(null, $result);
                return redirect()->route('accounting.reconciliation.index')
                    ->with('not_permitted', $notice['message']);
            }
            session()->put('accounting_health.last_health', $customerHealth);
            $failedChecks = (int) data_get($result, 'summary.failed', 0);
            if ($failedChecks > 0) {
                $notice = $this->rememberSafeHealthFailure(null, $result);
                return redirect()->route('accounting.reconciliation.index', ['failed_checks' => 1])
                    ->with('not_permitted', $notice['message']);
            }
            $hasActionableIssues = !empty($customerHealth['issues']) || ($result['status'] ?? null) === 'fail';
            $message = $hasActionableIssues
                ? __('db.accounting_health_check_completed_with_issues')
                : __('db.accounting_health_check_completed_success');

            return redirect()->route('accounting.reconciliation.index')
                ->with($hasActionableIssues ? 'not_permitted' : 'message', $message);
        } catch (Throwable $e) {
            $notice = $this->rememberSafeHealthFailure($e);
            $workerDiagnostics = $e instanceof HealthCheckExecutionException ? $e->diagnostics : [];
            Log::error('Accounting web health check failed.', array_merge($workerDiagnostics, [
                'initiated_by' => Auth::id(),
                'reference' => $notice['reference'],
                'failure_code' => data_get($notice, 'technical.failure_code'),
                'failed_stage' => data_get($notice, 'technical.failed_stage'),
                'exception_class' => $e::class,
            ]));

            return redirect()->route('accounting.reconciliation.index')
                ->with('not_permitted', $notice['message']);
        }
    }

    private function rememberSafeHealthFailure(?Throwable $exception = null, array $result = []): array
    {
        $reference = 'HC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        $failureCode = $exception instanceof HealthCheckExecutionException ? $exception->failureCode : 'invalid_health_result';
        $failedStage = $exception instanceof HealthCheckExecutionException ? $exception->failedStage : 'health_result';
        $summary = (array) ($result['summary'] ?? ['passed' => 0, 'failed' => 0, 'not_run' => 0]);
        $detectorFailure = (int) ($summary['failed'] ?? 0) > 0;
        if ($detectorFailure) {
            $failureCode = 'detector_error';
            $failedStage = 'bounded_detectors';
            $message = __('db.accounting_health_completed_with_errors', ['count' => $summary['failed'], 'reference' => $reference]);
            $category = 'detector_failure';
            $action = '#failed-health-checks';
        } elseif ($failureCode === 'schema_incompatible') {
            $message = __('db.accounting_health_schema_update_required', ['reference' => $reference]);
            $category = 'setup_required';
            $action = '#deep-scan-setup';
        } else {
            $message = __('db.accounting_health_temporary_failure', ['reference' => $reference]);
            $category = 'temporary_failure';
            $action = route('accounting.reconciliation.index');
        }
        $notice = [
            'category' => $category,
            'message' => $message,
            'next_action' => $detectorFailure ? __('db.accounting_health_open_failed_checks') : __('db.accounting_health_try_again'),
            'action_url' => $action,
            'reference' => $reference,
            'checked_at' => now()->toDateTimeString(),
            'summary' => $summary,
            'technical' => ['failure_code' => $failureCode, 'failed_stage' => $failedStage],
        ];
        session()->put('accounting_health.run_notice', $notice);
        session()->put('accounting_health.last_error', [
            'area' => __('db.accounting_health_title'),
            'status' => __('db.accounting_health_status_action_required'),
            'finding' => $message,
            'count' => max(1, (int) ($summary['failed'] ?? 0)),
            'checked_at' => $notice['checked_at'],
            'reference' => $reference,
            'failure_code' => $failureCode,
            'failed_stage' => $failedStage,
        ]);
        return $notice;
    }

    public function retry($id, AccountingJournalRetryService $journalRetry)
    {
        if (!$this->userHasAnyAccountingPermission(self::TECHNICAL_ACCESS_PERMISSIONS)) {
            return redirect('/')->with('not_permitted', __('db.Sorry! You are not allowed to access this module'));
        }

        $record = AccountingSyncQueue::findOrFail($id);
        $attemptedAt = now();
        
        $modelClass = $record->source_type;
        if (!class_exists($modelClass)) {
            $this->markRetryFailed($record, 'Model class not found: ' . $modelClass, $attemptedAt);
            return redirect()->back()->with('error', __('db.accounting_health_retry_model_missing', ['model' => $modelClass]));
        }
        
        $model = $modelClass::find($record->source_id);
        if (!$model) {
            $this->markRetryFailed($record, 'Source record deleted or not found.', $attemptedAt);
            return redirect()->back()->with('error', __('db.accounting_health_retry_source_missing'));
        }

        $classification = $journalRetry->classify($modelClass, (int) $record->source_id);
        if ($classification['status'] === 'already_posted_valid') {
            $this->markRetrySucceeded($record, $attemptedAt);
            $model->forceFill(['accounting_status' => 'posted'])->saveQuietly();
            return redirect()->back()->with('message', __('db.accounting_health_retry_success'));
        }
        if (in_array($classification['status'], ['existing_invalid', 'duplicate'], true)) {
            $error = $classification['reason'];
            $this->markRetryFailed($record, $error, $attemptedAt);
            return redirect()->back()->with('error', __('db.accounting_health_retry_failed', ['error' => $error]));
        }

        // Determine which method to call based on model
        $result = null;
        try {
            switch ($modelClass) {
                case Sale::class:
                    $result = $this->accountingService->recordSale($model);
                    break;
                case Purchase::class:
                    $result = $this->accountingService->recordPurchase($model);
                    break;
                case Returns::class:
                    $result = $this->accountingService->recordSaleReturn($model);
                    break;
                case ReturnPurchase::class:
                    $result = $this->accountingService->recordPurchaseReturn($model);
                    break;
                case Payment::class:
                    $result = $this->accountingService->recordPayment($model);
                    break;
                case Expense::class:
                    $result = $this->accountingService->recordExpense($model);
                    break;
                case Income::class:
                    $result = $this->accountingService->recordIncome($model);
                    break;
                case Payroll::class:
                    $result = $this->accountingService->recordPayroll($model);
                    break;
                case MoneyTransfer::class:
                    $result = $this->accountingService->recordMoneyTransfer($model);
                    break;
                // Additional types can be added here
                default:
                    $this->markRetryFailed($record, 'Retry logic not implemented for this model type.', $attemptedAt);
                    return redirect()->back()->with('error', __('db.accounting_health_retry_not_supported'));
            }
        } catch (Throwable $e) {
            report($e);
            $this->markRetryFailed($record, $e->getMessage(), $attemptedAt);
            return redirect()->back()->with('error', __('db.accounting_health_retry_failed', ['error' => $e->getMessage()]));
        }

        if ($result && $result->success) {
            $verified = $journalRetry->classify($modelClass, (int) $record->source_id);
            if ($verified['status'] !== 'already_posted_valid') {
                $error = $verified['reason'] ?? 'The retry did not produce one valid unreversed journal.';
                $this->markRetryFailed($record, $error, $attemptedAt);
                return redirect()->back()->with('error', __('db.accounting_health_retry_failed', ['error' => $error]));
            }
            $this->markRetrySucceeded($record, $attemptedAt);
            if (Schema::hasColumn($model->getTable(), 'accounting_status')) {
                $model->forceFill(['accounting_status' => 'posted'])->saveQuietly();
            }
            return redirect()->back()->with('message', __('db.accounting_health_retry_success'));
        } else {
            $error = $result ? $result->error : __('db.accounting_health_unknown_error');
            $this->markRetryFailed($record, $error, $attemptedAt);
            return redirect()->back()->with('error', __('db.accounting_health_retry_failed', ['error' => $error]));
        }
    }

    private function markRetrySucceeded(AccountingSyncQueue $record, $timestamp): void
    {
        $record->status = 'posted';
        $record->attempts = (int) $record->attempts + 1;
        $record->last_attempt_at = $timestamp;
        $record->last_error = null;
        $record->posted_at = $record->posted_at ?: $timestamp;
        $record->resolved_at = $record->resolved_at ?: $timestamp;
        $record->last_success_at = $timestamp;
        $record->save();
    }

    private function markRetryFailed(AccountingSyncQueue $record, string $error, $timestamp): void
    {
        $record->status = 'failed';
        $record->attempts = (int) $record->attempts + 1;
        $record->last_attempt_at = $timestamp;
        $record->last_error = $error;
        $record->save();
    }

    private function userHasAnyAccountingPermission(array $permissions): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return DB::table('permissions')
            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $user->role_id)
            ->whereIn('permissions.name', $permissions)
            ->exists();
    }
}
