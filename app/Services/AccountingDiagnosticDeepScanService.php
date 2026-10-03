<?php

namespace App\Services;

use App\Models\AccountingDiagnosticScan;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingEventFamilyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountingDiagnosticDeepScanService
{
    public const FORMAT_VERSION = 3;
    public const DEFAULT_CHUNK_SIZE = 500;
    private const TERMINAL = ['completed', 'completed_with_warnings', 'cancelled', 'superseded'];

    public function start(?int $requestedWarehouseId = null): AccountingDiagnosticScan
    {
        $access = app(WarehouseAccessService::class);
        abort_if($access->isPortalIdentity() || $access->classification() === WarehouseAccessService::INVALID_OPERATIONAL, 403);
        if ($access->isRestricted() && $requestedWarehouseId !== null && $requestedWarehouseId !== $access->warehouseId()) abort(403);
        if ($requestedWarehouseId !== null) abort_unless(Warehouse::withoutGlobalScope('authorized_warehouse')->whereKey($requestedWarehouseId)->where('is_active', true)->exists(), 403);
        $warehouseId = $access->isRestricted() ? $access->warehouseId() : $requestedWarehouseId;
        $scope = $warehouseId ? 'warehouse' : 'global';
        $detectorFingerprint = $this->detectorFingerprint();
        $lockName = $this->launchLockName($scope, $warehouseId, $detectorFingerprint);
        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])->acquired ?? 0);
        if ($acquired !== 1) {
            throw ValidationException::withMessages(['scan' => 'Another scan launch for this scope is being finalized. Try again shortly.']);
        }
        try {
            return DB::transaction(function () use ($warehouseId, $scope, $detectorFingerprint) {
            $existing = AccountingDiagnosticScan::where('scope', $scope)->where('warehouse_id', $warehouseId)
                ->where('detector_fingerprint', $detectorFingerprint)
                ->whereIn('status', ['created', 'queued', 'running', 'paused'])->lockForUpdate()->first();
            if ($existing) return $existing;
            $watermarks = $this->captureWatermarks();
            return AccountingDiagnosticScan::create(['scan_key' => (string) Str::uuid(), 'scope' => $scope,
                'warehouse_id' => $warehouseId, 'requested_by' => auth()->id(), 'status' => 'created',
                'consistency_status' => 'bounded', 'format_version' => self::FORMAT_VERSION,
                'detector_fingerprint' => $detectorFingerprint, 'dataset_as_of' => now(),
                'watermark' => $watermarks['journal_entries'] ?? 0, 'watermarks' => $watermarks,
                'fingerprints' => $this->captureFingerprints($watermarks), 'checkpoints' => [], 'results' => [],
                'progress_details' => [], 'progress' => 0, 'rows_examined' => 0, 'findings_count' => 0]);
            });
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    public function markQueued(AccountingDiagnosticScan $scan): AccountingDiagnosticScan
    {
        return DB::transaction(function () use ($scan) {
            $locked = AccountingDiagnosticScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'created' || $locked->status === 'paused') {
                $locked->update(['status' => 'queued', 'version' => $locked->version + 1]);
            }
            return $locked->fresh();
        });
    }

    /** Process exactly one bounded, atomically checkpointed keyset chunk. */
    public function processChunk(AccountingDiagnosticScan $scan, int $chunkSize = self::DEFAULT_CHUNK_SIZE): AccountingDiagnosticScan
    {
        $chunkSize = max(50, min(2000, $chunkSize));
        $processed = DB::transaction(function () use ($scan, $chunkSize) {
            $locked = AccountingDiagnosticScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::TERMINAL, true)) return $locked;
            if ($locked->cancellation_requested_at) {
                $locked->update(['status' => 'cancelled', 'consistency_status' => 'partial', 'cancelled_at' => now(),
                    'heartbeat_at' => now(), 'version' => $locked->version + 1]);
                return $locked->fresh();
            }
            if ((int) $locked->format_version !== self::FORMAT_VERSION) {
                $locked->update(['status' => 'failed', 'consistency_status' => 'incompatible', 'failure_summary' => 'Checkpoint format is incompatible; start a new scan.']);
                return $locked->fresh();
            }
            $families = app(AccountingEventFamilyRegistry::class)->all();
            $checkpoints = $locked->checkpoints ?: [];
            $key = collect(array_keys($families))->first(fn ($name) => ($checkpoints[$name]['status'] ?? null) !== 'completed');
            if ($key === null) return $locked->fresh();
            $family = $families[$key];
            if ($family['availability'] !== 'enabled' || !Schema::hasTable($family['table'])) {
                $checkpoints[$key] = $this->checkpoint($family, 'completed', 'not_applicable');
                $locked->update(['checkpoints' => $checkpoints, 'status' => 'running', 'heartbeat_at' => now(), 'version' => $locked->version + 1]);
                return $locked->fresh();
            }
            $state = $checkpoints[$key] ?? $this->checkpoint($family);
            $upper = (int) (($locked->watermarks ?: [])[$family['table']] ?? 0);
            $query = DB::table($family['table'].' as src')->where('src.id', '>', $state['last_id'])
                ->where('src.id', '<=', $upper)->orderBy('src.id')->limit($chunkSize);
            if ($locked->scope === 'warehouse' && Schema::hasColumn($family['table'], 'warehouse_id'))
                $query->where('src.warehouse_id', $locked->warehouse_id);
            $this->familyFilter($query, $key);
            $rows = $query->get();
            $currencyCodes = Schema::hasTable('currencies') ? DB::table('currencies')->pluck('code', 'id')->all() : [];
            $evidence = $this->loadChunkEvidence($family, $rows, $locked);
            foreach ($rows as $row) {
                $classification = $this->classify($row, $evidence);
                $state['processed_count']++;
                $state['classifications'][$classification] = ($state['classifications'][$classification] ?? 0) + 1;
                if (!in_array($classification, ['correctly_posted', 'pre_cutover_excluded', 'accounting_disabled', 'properly_reversed'], true)) {
                    $state['affected_count']++;
                    $this->aggregateAmount($state, $row, $currencyCodes);
                    if (count($state['samples']) < HistoricalIntegrityDiagnosticService::SAMPLE_LIMIT)
                        $state['samples'][] = ['id' => (int) $row->id, 'classification' => $classification];
                }
                $state['last_id'] = (int) $row->id;
            }
            $state['chunks']++;
            $state['upper_watermark'] = $upper;
            $state['status'] = $rows->count() < $chunkSize ? 'completed' : 'running';
            $checkpoints[$key] = $state;
            $total = count($families);
            $done = collect($checkpoints)->where('status', 'completed')->count();
            $rowsExamined = collect($checkpoints)->sum(fn ($checkpoint) => (int) ($checkpoint['processed_count'] ?? 0));
            $findingsCount = collect($checkpoints)->sum(fn ($checkpoint) => (int) ($checkpoint['affected_count'] ?? 0));
            $locked->update(['checkpoints' => $checkpoints, 'progress_details' => $this->progressDetails($checkpoints),
                'current_check' => $key, 'status' => 'running', 'heartbeat_at' => now(),
                'rows_examined' => $rowsExamined, 'findings_count' => $findingsCount,
                'started_at' => $locked->started_at ?: now(), 'progress' => min(80, (int) floor($done * 80 / $total)),
                'version' => $locked->version + 1]);
            return $locked->fresh();
        });
        if (in_array($processed->status, self::TERMINAL, true)) return $processed;
        $families = app(AccountingEventFamilyRegistry::class)->all();
        $complete = collect($families)->keys()->every(fn ($key) => (($processed->checkpoints ?: [])[$key]['status'] ?? null) === 'completed');
        return $complete ? $this->processFinalDetector($processed) : $processed;
    }

    private function processFinalDetector(AccountingDiagnosticScan $scan): AccountingDiagnosticScan
    {
        $stages = $this->finalDetectorStages();
        $checkpoints = $scan->checkpoints ?: [];
        $stage = collect($stages)->first(fn ($definition, $key) => ($checkpoints[$key]['status'] ?? null) !== 'completed');
        if ($stage === null) return $this->finalize($scan);
        $stageKey = (string) collect($stages)->search($stage, true);
        $lockName = 'f012stage:'.substr(hash('sha256', (string) $scan->scan_key), 0, 53);
        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])->acquired ?? 0);
        if ($acquired !== 1) return $scan->fresh();
        try {
            $fresh = DB::transaction(function () use ($scan, $stageKey) {
                $locked = AccountingDiagnosticScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
                if (in_array($locked->status, self::TERMINAL, true)) return $locked;
                if ($locked->cancellation_requested_at) {
                    $locked->update(['status' => 'cancelled', 'consistency_status' => 'partial', 'cancelled_at' => now(),
                        'heartbeat_at' => now(), 'version' => $locked->version + 1]);
                    return $locked->fresh();
                }
                $checkpoints = $locked->checkpoints ?: [];
                if (($checkpoints[$stageKey]['status'] ?? null) === 'completed') return $locked;
                $checkpoints[$stageKey] = ['format_version' => self::FORMAT_VERSION, 'stage' => 'detector',
                    'status' => 'running', 'started_at' => now()->toIso8601String(), 'attempts' => (int) (($checkpoints[$stageKey]['attempts'] ?? 0) + 1)];
                $locked->update(['checkpoints' => $checkpoints, 'current_check' => $stageKey, 'status' => 'running',
                    'heartbeat_at' => now(), 'version' => $locked->version + 1]);
                return $locked->fresh();
            });
            if (in_array($fresh->status, self::TERMINAL, true)) return $fresh;
            if ((($fresh->checkpoints ?: [])[$stageKey]['status'] ?? null) === 'completed') return $fresh;

            $result = $this->runFinalDetector($fresh, $stage);
            $result = $this->normalizeFinalDetectorResult($fresh, $stage['group'], $result);
            return DB::transaction(function () use ($fresh, $stageKey, $stage, $result) {
                $locked = AccountingDiagnosticScan::whereKey($fresh->id)->lockForUpdate()->firstOrFail();
                if (in_array($locked->status, self::TERMINAL, true)) return $locked;
                if ($locked->cancellation_requested_at) {
                    $locked->update(['status' => 'cancelled', 'consistency_status' => 'partial', 'cancelled_at' => now(),
                        'heartbeat_at' => now(), 'version' => $locked->version + 1]);
                    return $locked->fresh();
                }
                $checkpoints = $locked->checkpoints ?: [];
                $checkpoints[$stageKey] = array_merge($checkpoints[$stageKey] ?? [], ['status' => 'completed',
                    'completed_at' => now()->toIso8601String(), 'result_status' => $result['status'] ?? 'scan_failed',
                    'authoritative_affected_record_count' => $result['authoritative_affected_record_count'] ?? null]);
                $results = $locked->results ?: [];
                $results[$stage['key']] = $result;
                $done = collect($this->finalDetectorStages())->keys()->filter(
                    fn ($key) => (($checkpoints[$key]['status'] ?? null) === 'completed')
                )->count();
                $locked->update(['checkpoints' => $checkpoints, 'results' => $results,
                    'progress_details' => $this->progressDetails($checkpoints), 'current_check' => $stageKey,
                    'progress' => min(99, 80 + (int) floor($done * 19 / max(1, count($this->finalDetectorStages())))),
                    'heartbeat_at' => now(), 'version' => $locked->version + 1]);
                return $locked->fresh();
            });
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function finalDetectorStages(): array
    {
        $stages = [];
        foreach (app(HistoricalIntegrityDiagnosticService::class)->checkKeys() as $key) {
            $stages['detector:historical:'.$key] = ['group' => 'historical', 'key' => $key];
        }
        foreach (app(TaxAccountingDiagnosticService::class)->checkKeys() as $key) {
            $stages['detector:tax:'.$key] = ['group' => 'tax', 'key' => $key];
        }
        foreach (app(AccountingCoreIntegrityDiagnosticService::class)->checkKeys() as $key) {
            $stages['detector:core:'.$key] = ['group' => 'core', 'key' => $key];
        }
        return $stages;
    }

    private function runFinalDetector(AccountingDiagnosticScan $scan, array $stage): array
    {
        $warehouseId = $scan->scope === 'warehouse' ? (int) $scan->warehouse_id : null;
        $cutoff = $scan->dataset_as_of ?: $scan->created_at;
        return match ($stage['group']) {
            'historical' => app(HistoricalIntegrityDiagnosticService::class)->scan(
                'deep', $cutoff, [$stage['key']], $warehouseId, $scan->watermarks ?: []
            )[$stage['key']],
            'tax' => app(TaxAccountingDiagnosticService::class)->scan(
                'deep', $cutoff, $warehouseId, $scan->watermarks ?: [], [$stage['key']]
            )[$stage['key']],
            'core' => app(AccountingCoreIntegrityDiagnosticService::class)->scan(
                $warehouseId, [$stage['key']], $scan->watermarks ?: []
            )[$stage['key']],
        };
    }

    private function normalizeFinalDetectorResult(AccountingDiagnosticScan $scan, string $group, array $finding): array
    {
        $finding['scan_mode'] = 'deep';
        $finding['dataset_high_water_mark'] = $scan->watermarks ?: [];
        if (($finding['status'] ?? null) === 'not_applicable') {
            $finding['scan_status'] = 'completed';
            return $finding;
        }
        if (($finding['status'] ?? null) === 'scan_failed') {
            $finding['scan_status'] = 'scan_failed';
            $finding['count_is_exact'] = false;
            $finding['authoritative_affected_record_count'] = null;
            return $finding;
        }
        $stale = $this->hasDrift($scan);
        $boundaryCertifiedCoreChecks = [
            'unbalanced_active_journals', 'duplicate_active_journal_families', 'missing_required_semantic_mappings',
            'duplicate_semantic_mappings_or_account_codes', 'stalled_accounting_events', 'closed_period_late_postings',
            'warehouse_attribution_integrity', 'orphaned_payment_account_mappings',
            'customer_deposit_liability_disagreement', 'supplier_control_account_disagreement',
        ];
        if ($group === 'core' && !in_array($finding['check_key'] ?? '', array_map(fn ($key) => 'f012.'.$key, $boundaryCertifiedCoreChecks), true)) {
            if (($finding['status'] ?? null) !== 'inconclusive') $finding['status'] = 'inconclusive';
            $finding['scan_status'] = 'completed_with_warnings';
            $finding['count_is_exact'] = false;
            $finding['authoritative_affected_record_count'] = null;
            $finding['limitations'][] = 'This core detector does not have complete immutable evidence at the captured Deep Scan boundary.';
            return $finding;
        }
        if ($stale || !($finding['count_is_exact'] ?? false)) {
            $finding['scan_status'] = 'completed_with_warnings';
            $finding['count_is_exact'] = false;
            $finding['authoritative_affected_record_count'] = null;
            $finding['limitations'][] = $stale
                ? 'Captured records changed or were deleted; a new scan is required.'
                : 'The watermark-bounded detector reached its configured record ceiling or lacks authoritative evidence.';
            if (($finding['status'] ?? null) === 'healthy') $finding['status'] = 'inconclusive';
        } else {
            $finding['scan_status'] = 'completed';
        }
        return $finding;
    }

    private function finalize(AccountingDiagnosticScan $scan): AccountingDiagnosticScan
    {
        $lockName = 'f012final:'.substr(hash('sha256', (string) $scan->scan_key), 0, 54);
        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])->acquired ?? 0);
        if ($acquired !== 1) return $scan->fresh();
        try {
            $fresh = DB::transaction(function () use ($scan) {
                $locked = AccountingDiagnosticScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
                if (in_array($locked->status, self::TERMINAL, true)) return $locked;
                if ($locked->cancellation_requested_at) {
                    $locked->update(['status' => 'cancelled', 'consistency_status' => 'partial', 'cancelled_at' => now(),
                        'heartbeat_at' => now(), 'version' => $locked->version + 1]);
                }
                return $locked->fresh();
            });
            if (in_array($fresh->status, self::TERMINAL, true)) return $fresh;
            return $this->completeLocked($fresh);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    public function resume(AccountingDiagnosticScan $scan): AccountingDiagnosticScan
    {
        $this->authorize($scan);
        if (in_array($scan->status, self::TERMINAL, true)) throw ValidationException::withMessages(['scan' => 'This terminal scan cannot be resumed.']);
        if ($scan->consistency_status === 'incompatible') throw ValidationException::withMessages(['scan' => 'Start a new scan because this checkpoint is incompatible.']);
        $scan->update(['status' => 'queued', 'failure_summary' => null, 'last_error' => null, 'failed_at' => null,
            'paused_at' => null, 'retry_count' => $scan->retry_count + 1, 'version' => $scan->version + 1]);
        return $scan->fresh();
    }

    public function cancel(AccountingDiagnosticScan $scan): AccountingDiagnosticScan
    {
        $this->authorize($scan);
        return DB::transaction(function () use ($scan) {
            $locked = AccountingDiagnosticScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::TERMINAL, true)) return $locked;
            $locked->update(['status' => 'cancelled', 'consistency_status' => 'partial',
                'cancellation_requested_at' => now(), 'cancelled_at' => now(), 'heartbeat_at' => now(),
                'version' => $locked->version + 1]);
            return $locked->fresh();
        });
    }

    public function pauseStale(AccountingDiagnosticScan $scan, int $staleAfterSeconds = 300): AccountingDiagnosticScan
    {
        return DB::transaction(function () use ($scan, $staleAfterSeconds) {
            $locked = AccountingDiagnosticScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'running' && (!$locked->heartbeat_at || $locked->heartbeat_at->lt(now()->subSeconds($staleAfterSeconds)))) {
                $locked->update(['status' => 'paused', 'paused_at' => now(), 'failure_summary' => 'Worker heartbeat became stale; resume from the last committed checkpoint.', 'version' => $locked->version + 1]);
            }
            return $locked->fresh();
        });
    }

    public function authorize(AccountingDiagnosticScan $scan): void
    {
        $access = app(WarehouseAccessService::class);
        abort_if($access->isPortalIdentity() || $access->classification() === WarehouseAccessService::INVALID_OPERATIONAL, 403);
        abort_if($access->isRestricted() && ($scan->scope !== 'warehouse' || (int) $scan->warehouse_id !== $access->warehouseId()), 403);
    }

    private function completeLocked(AccountingDiagnosticScan $scan): AccountingDiagnosticScan
    {
        $stale = $this->hasDrift($scan);
        $results = $scan->results ?: [];
        $required = collect($this->finalDetectorStages())->pluck('key')->all();
        if (array_diff($required, array_keys($results))) {
            return $scan->fresh();
        }
        if ($stale) {
            foreach ($required as $key) {
                if (in_array($results[$key]['status'] ?? null, ['not_applicable', 'scan_failed'], true)) continue;
                $results[$key]['status'] = 'inconclusive';
                $results[$key]['scan_status'] = 'completed_with_warnings';
                $results[$key]['count_is_exact'] = false;
                $results[$key]['authoritative_affected_record_count'] = null;
                $results[$key]['limitations'][] = 'Captured records changed or were deleted after this detector checkpoint; start a new scan.';
            }
        }
        $affected = collect($scan->checkpoints ?: [])->sum(fn ($state) => (int) ($state['affected_count'] ?? 0));
        $checkKey = 'f012.registry_event_consistency';
        $result = ['check_key' => $checkKey, 'detector_version' => (string) self::FORMAT_VERSION,
            'detector_fingerprint' => $scan->detector_fingerprint, 'status' => $stale ? 'inconclusive' : ($affected ? 'critical' : 'healthy'),
            'severity' => $stale ? 'warning' : ($affected ? 'critical' : 'info'), 'scope' => $scan->scope,
            'warehouse_id' => $scan->warehouse_id, 'affected_count' => $affected,
            'authoritative_affected_record_count' => $stale ? null : $affected,
            'scan_status' => $stale ? 'completed_with_warnings' : 'completed', 'count_is_exact' => !$stale, 'amount_is_exact' => false,
            'sample_count' => collect($scan->checkpoints ?: [])->sum(fn ($state) => count($state['samples'] ?? [])),
            'sample_limit' => HistoricalIntegrityDiagnosticService::SAMPLE_LIMIT, 'sample_records' => [],
            'dataset_high_water_mark' => $scan->watermarks, 'scanned_at' => now()->toIso8601String(),
            'plain_language_explanation' => $affected ? 'One or more accounting event sources disagree with their active journal or queue evidence.' : 'Checked accounting event sources agree with retained journal and queue evidence.',
            'recommended_next_action' => $affected ? 'Review the classified bounded samples and create a repair preview only where source evidence is authoritative.' : 'No action is required.',
            'repair_available' => false, 'repair_unavailable_reasons' => ['Repairs are offered per exact finding, never for the aggregate registry result.'],
            'technical_evidence' => ['checkpoints' => $scan->checkpoints], 'checkpoints' => $scan->checkpoints, 'limitations' => $stale
                ? ['Captured records changed or were deleted; a new scan is required.']
                : ['Amount comparison is inconclusive for families without authoritative preserved currency metadata.']];
        $hasWarnings = $stale || collect($results)->contains(fn ($item) => ($item['status'] ?? null) !== 'healthy');
        return DB::transaction(function () use ($scan, $hasWarnings, $stale, $results, $result) {
            $locked = AccountingDiagnosticScan::whereKey($scan->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::TERMINAL, true)) return $locked;
            if ($locked->cancellation_requested_at) {
                $locked->update(['status' => 'cancelled', 'consistency_status' => 'partial', 'cancelled_at' => now(),
                    'heartbeat_at' => now(), 'version' => $locked->version + 1]);
                return $locked->fresh();
            }
            $locked->update(['status' => $hasWarnings ? 'completed_with_warnings' : 'completed',
                'consistency_status' => $stale ? 'stale' : 'partial', 'progress' => 100, 'current_check' => null,
                'results' => array_merge($results, ['registry_event_consistency' => $result]),
                'completed_at' => now(), 'heartbeat_at' => now(), 'version' => $locked->version + 1]);
            return $locked->fresh();
        });
    }

    private function detectorFingerprint(): string
    {
        $definitions = collect(app(AccountingEventFamilyRegistry::class)->all())->map(fn ($family, $key) => [
            'key' => $key, 'table' => $family['table'], 'source_type' => $family['source_type'],
            'availability' => $family['availability'], 'format_version' => self::FORMAT_VERSION,
        ])->values()->all();
        $definitions[] = ['group' => 'historical', 'version' => HistoricalIntegrityDiagnosticService::DETECTOR_VERSION,
            'keys' => app(HistoricalIntegrityDiagnosticService::class)->checkKeys()];
        $definitions[] = ['group' => 'tax', 'version' => TaxAccountingDiagnosticService::DETECTOR_VERSION,
            'keys' => app(TaxAccountingDiagnosticService::class)->checkKeys()];
        $definitions[] = ['group' => 'core', 'version' => AccountingCoreIntegrityDiagnosticService::VERSION,
            'keys' => app(AccountingCoreIntegrityDiagnosticService::class)->checkKeys()];
        return hash('sha256', json_encode($definitions, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function launchLockName(string $scope, ?int $warehouseId, string $detectorFingerprint): string
    {
        return 'f012scan:'.substr(hash('sha256', $scope.':'.($warehouseId ?? 'global').':'.$detectorFingerprint), 0, 54);
    }

    private function progressDetails(array $checkpoints): array
    {
        return collect($checkpoints)->map(fn ($state) => [
            'status' => $state['status'] ?? 'created', 'cursor' => (int) ($state['last_id'] ?? 0),
            'rows_examined' => (int) ($state['processed_count'] ?? 0),
            'findings_count' => (int) ($state['affected_count'] ?? 0), 'chunks' => (int) ($state['chunks'] ?? 0),
        ])->all();
    }

    private function captureWatermarks(): array
    {
        $tables = collect(app(AccountingEventFamilyRegistry::class)->all())->pluck('table')
            ->push('accounting_configs')->push('accounting_accounts')->push('account_mappings')->push('currencies')
            ->push('products')->push('product_sales')->push('product_returns')
            ->push('accounting_periods')->push('accounts')->push('deposits')->push('customers')->push('suppliers')
            ->push('product_warehouse')->push('journal_entries')->push('journal_lines')->push('accounting_sync_queue')->unique();
        return $tables->filter(fn ($table) => Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => (int) DB::table($table)->max('id')])->all();
    }

    private function captureFingerprints(array $watermarks): array
    {
        $out = [];
        foreach ($watermarks as $table => $max) {
            $row = $this->fingerprintQuery($table, $max);
            $out[$table] = ['count' => (int) $row->row_count, 'id_sum' => (string) $row->id_sum, 'content_xor' => (string) $row->content_xor];
        }
        return $out;
    }

    private function hasDrift(AccountingDiagnosticScan $scan): bool
    {
        foreach ($scan->fingerprints ?: [] as $table => $expected) {
            if (!Schema::hasTable($table)) return true;
            $max = (int) (($scan->watermarks ?: [])[$table] ?? 0);
            $query = DB::table($table)->where('id', '<=', $max);
            $row = $this->fingerprintQuery($table, $max);
            if ((int) $row->row_count !== (int) $expected['count'] || (string) $row->id_sum !== (string) $expected['id_sum']
                || (string) $row->content_xor !== (string) ($expected['content_xor'] ?? '')) return true;
            if (Schema::hasColumn($table, 'updated_at') && (clone $query)->where('updated_at', '>', $scan->created_at)->exists()) return true;
        }
        return false;
    }

    private function fingerprintQuery(string $table, int $max): object
    {
        $candidates = ['id','updated_at','accounting_status','status','amount','grand_total','opening_balance','deposit','expense','total_tax',
            'currency_id','exchange_rate','warehouse_id','source_type','source_id','event_type','related_journal_entry_id','debit','credit',
            'mapped_type','mapped_id','accounting_account_id','code','is_active','is_closed','closed_at','entry_date','imei_number','qty'];
        $columns = array_values(array_intersect($candidates, Schema::getColumnListing($table)));
        $parts = array_map(fn ($column) => "COALESCE(CAST(`{$column}` AS CHAR), '<NULL>')", $columns);
        $expression = 'CRC32(CONCAT_WS(CHAR(31),'.implode(',', $parts).'))';
        return DB::table($table)->where('id', '<=', $max)
            ->selectRaw("COUNT(*) row_count, COALESCE(SUM(id),0) id_sum, COALESCE(BIT_XOR({$expression}),0) content_xor")->first();
    }

    /** A fixed number of evidence queries per chunk, independent of source-row count. */
    private function loadChunkEvidence(array $family, $rows, AccountingDiagnosticScan $scan): array
    {
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (!$ids) return ['active' => [], 'reversed' => [], 'queue' => []];
        $maxJournal = (int) (($scan->watermarks ?: [])['journal_entries'] ?? 0);
        $journal = DB::table('journal_entries')->where('source_type', $family['source_type'])
            ->whereIn('source_id', $ids)->where('id', '<=', $maxJournal);
        if ($scan->scope === 'warehouse') $journal->where('warehouse_id', $scan->warehouse_id);
        $journals = $journal->get(['id', 'source_id', 'related_journal_entry_id']);
        $reversedIds = $journals->whereNotNull('related_journal_entry_id')->pluck('related_journal_entry_id')->map(fn ($id) => (int) $id)->flip();
        $active = $journals->whereNull('related_journal_entry_id')->reject(fn ($entry) => $reversedIds->has((int) $entry->id))
            ->groupBy('source_id')->map->count()->all();
        $reversed = $journals->whereNotNull('related_journal_entry_id')->groupBy('source_id')->map->count()->all();
        $queue = Schema::hasTable('accounting_sync_queue') ? DB::table('accounting_sync_queue')
            ->where('source_type', $family['source_type'])->whereIn('source_id', $ids)
            ->where('id', '<=', (int) (($scan->watermarks ?: [])['accounting_sync_queue'] ?? 0))
            ->get(['source_id', 'status'])->keyBy('source_id')->all() : [];
        return ['active' => $active, 'reversed' => $reversed, 'queue' => $queue];
    }

    private function classify(object $row, array $evidence): string
    {
        $active = (int) ($evidence['active'][$row->id] ?? 0);
        $queue = $evidence['queue'][$row->id] ?? null;
        if ($active > 1) return 'duplicate_active_journal';
        if ($active === 1 && $queue?->status === 'failed') return 'queue_failed_but_journal_active';
        if ($active === 1) return 'correctly_posted';
        if ($queue?->status === 'posted') return 'queue_posted_but_journal_absent';
        if ($queue?->status === 'failed') return 'failed_with_queue_evidence';
        if ($queue?->status === 'pending') return 'pending_not_attempted';
        if (($row->accounting_status ?? null) === 'reversed' && ($evidence['reversed'][$row->id] ?? 0)) return 'properly_reversed';
        if (($row->accounting_status ?? null) === 'posted') return 'posted_source_missing_active_journal';
        return 'inconclusive_missing_authoritative_evidence';
    }

    private function checkpoint(array $family, string $status = 'running', ?string $reason = null): array
    {
        return ['format_version' => self::FORMAT_VERSION, 'status' => $status, 'reason' => $reason, 'stage' => 'sources',
            'last_id' => 0, 'upper_watermark' => 0, 'processed_count' => 0, 'affected_count' => 0, 'chunks' => 0,
            'transaction_currency' => [], 'base_amount' => '0.0000', 'base_amount_status' => 'exact',
            'rate_source' => 'source_preserved_exchange_rate', 'decimal_scale' => 4,
            'included_records' => 0, 'excluded_records' => 0, 'exclusions' => [],
            'samples' => [], 'classifications' => [], 'source_table' => $family['table']];
    }

    private function familyFilter($query, string $key): void
    {
        if ($key === 'sale_payments') $query->whereNotNull('src.sale_id')->whereNull('src.return_id');
        elseif ($key === 'purchase_payments') $query->whereNotNull('src.purchase_id')->whereNull('src.purchase_return_id')->whereNull('src.return_id');
        elseif ($key === 'sale_return_refunds') $query->whereNotNull('src.return_id');
        elseif ($key === 'purchase_return_refunds') $query->whereNotNull('src.purchase_return_id');
    }

    private function aggregateAmount(array &$state, object $row, array $currencyCodes): void
    {
        $amount = $row->amount ?? $row->grand_total ?? $row->net_salary ?? null;
        if ($amount === null || !is_numeric($amount)) {
            $state['excluded_records']++;
            $state['exclusions']['amount_unavailable'] = ($state['exclusions']['amount_unavailable'] ?? 0) + 1;
            $state['base_amount_status'] = 'partial';
            return;
        }
        $currencyId = isset($row->currency_id) ? (int) $row->currency_id : null;
        if (!$currencyId) {
            $state['excluded_records']++;
            $state['exclusions']['currency_unavailable'] = ($state['exclusions']['currency_unavailable'] ?? 0) + 1;
            $state['base_amount_status'] = 'partial';
            return;
        }
        $code = $currencyCodes[$currencyId] ?? 'CURRENCY_'.$currencyId;
        $state['transaction_currency'][$code] = bcadd($state['transaction_currency'][$code] ?? '0.0000', (string) $amount, 4);
        try {
            $base = app(\App\Services\Accounting\CurrencyNormalizationService::class)
                ->normalize($amount, $currencyId, $row->exchange_rate ?? null);
            $state['base_amount'] = bcadd($state['base_amount'], $base, 4);
            $state['included_records']++;
        } catch (\Throwable $exception) {
            $reason = (($row->exchange_rate ?? null) === null) ? 'missing_rate' : 'invalid_rate';
            $state['excluded_records']++;
            $state['exclusions'][$reason] = ($state['exclusions'][$reason] ?? 0) + 1;
            $state['base_amount_status'] = 'partial';
        }
        ksort($state['transaction_currency']);
    }
}
