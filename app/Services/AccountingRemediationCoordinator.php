<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AccountingRemediationCoordinator
{
    private const CRITICAL_STATUSES = ['planned', 'running', 'applied_pending_manifest'];

    public function __construct(
        private AccountingDatabaseFingerprintService $fingerprints,
        private AtomicRemediationManifestWriter $manifests
    ) {
    }

    public function execute(
        string $remediationKey,
        string $database,
        array $backupVerification,
        array $selectors,
        array $before,
        callable $apply,
        string $manifestPath,
        ?int $operatorId = null
    ): array {
        if (!Schema::hasTable('accounting_remediation_runs')) {
            throw new RuntimeException('Durable accounting remediation audit table is missing.');
        }

        $lockName = 'salepro_remediation_'.substr(hash('sha256', $database), 0, 32);
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw new RuntimeException('Another accounting remediation is already running for this database.');
        }

        $runId = null;
        $committed = false;
        $staged = null;
        try {
            $incomplete = DB::table('accounting_remediation_runs')
                ->whereIn('status', self::CRITICAL_STATUSES)->orderBy('id')->first();
            if ($incomplete) {
                throw new RuntimeException("Incomplete remediation run {$incomplete->run_uuid} must be recovered before another run.");
            }

            $fingerprintBefore = $this->fingerprints->capture();
            $plan = [
                'remediation_key' => $remediationKey,
                'database' => $database,
                'backup_sha256' => $backupVerification['sha256'],
                'selectors' => $selectors,
                'before' => $before,
            ];
            $planHash = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES));
            $prior = DB::table('accounting_remediation_runs')
                ->where('plan_hash', $planHash)->where('status', 'applied')->latest('id')->first();
            if ($prior) {
                return [
                    'already_applied' => true,
                    'run_uuid' => $prior->run_uuid,
                    'result' => json_decode((string) $prior->result_json, true),
                ];
            }

            $uuid = Str::uuid()->toString();
            $plannedPath = preg_replace('/\.json$/i', '', $manifestPath).'.planned.json';
            $runId = DB::table('accounting_remediation_runs')->insertGetId([
                'run_uuid' => $uuid,
                'remediation_key' => $remediationKey,
                'status' => 'planned',
                'database_name' => $database,
                'plan_hash' => $planHash,
                'operator_id' => $operatorId,
                'backup_path' => (string) $backupVerification['path'],
                'backup_sha256' => (string) $backupVerification['sha256'],
                'backup_verification_json' => json_encode($backupVerification, JSON_UNESCAPED_SLASHES),
                'fingerprint_before' => $fingerprintBefore['sha256'],
                'selectors_json' => json_encode($selectors, JSON_UNESCAPED_SLASHES),
                'before_state_json' => json_encode($before, JSON_UNESCAPED_SLASHES),
                'proposed_actions_json' => json_encode($this->proposedActions($before), JSON_UNESCAPED_SLASHES),
                'planned_manifest_path' => $plannedPath,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->manifests->write($plannedPath, [
                'run_uuid' => $uuid,
                'status' => 'planned',
                'plan_hash' => $planHash,
                'target_fingerprint' => $fingerprintBefore,
                'backup_verification' => $backupVerification,
                'selectors' => $selectors,
                'before' => $before,
            ]);

            $result = DB::transaction(function () use (
                $runId, $uuid, $planHash, $database, $backupVerification, $selectors,
                $before, $apply, $manifestPath, $fingerprintBefore, &$staged
            ) {
                $run = DB::table('accounting_remediation_runs')->where('id', $runId)->lockForUpdate()->first();
                if (!$run || $run->status !== 'planned') {
                    throw new RuntimeException('Remediation run state changed before apply.');
                }
                DB::table('accounting_remediation_runs')->where('id', $runId)->update([
                    'status' => 'running', 'started_at' => now(), 'updated_at' => now(),
                ]);

                $applied = $apply();
                $fingerprintAfter = $this->fingerprints->capture();
                $this->assertFinancialInvariants($before, $applied, $fingerprintAfter);

                $payload = [
                    'run_uuid' => $uuid,
                    'status' => 'applied',
                    'plan_hash' => $planHash,
                    'database' => $database,
                    'backup_verification' => $backupVerification,
                    'selectors' => $selectors,
                    'fingerprint_before' => $fingerprintBefore,
                    'fingerprint_after' => $fingerprintAfter,
                    'result' => $applied,
                ];
                $staged = $this->manifests->stage($manifestPath, $payload);
                DB::table('accounting_remediation_runs')->where('id', $runId)->update([
                    'status' => 'applied_pending_manifest',
                    'fingerprint_after' => $fingerprintAfter['sha256'],
                    'result_json' => json_encode($applied, JSON_UNESCAPED_SLASHES),
                    'manifest_payload_json' => $staged['payload_json'],
                    'manifest_path' => $staged['path'],
                    'manifest_temp_path' => $staged['temp_path'],
                    'manifest_sha256' => $staged['sha256'],
                    'applied_at' => now(),
                    'updated_at' => now(),
                ]);

                return $applied;
            });
            $committed = true;

            $this->manifests->finalize($staged['temp_path'], $staged['path'], $staged['sha256']);
            DB::table('accounting_remediation_runs')->where('id', $runId)->update([
                'status' => 'applied', 'manifest_temp_path' => null,
                'failure_message' => null, 'updated_at' => now(),
            ]);

            return ['already_applied' => false, 'run_uuid' => $uuid, 'result' => $result, 'manifest' => $staged];
        } catch (Throwable $exception) {
            if ($runId !== null && !$committed) {
                DB::table('accounting_remediation_runs')->where('id', $runId)->update([
                    'status' => 'failed',
                    'failure_message' => Str::limit($exception->getMessage(), 1000, ''),
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($runId !== null) {
                DB::table('accounting_remediation_runs')->where('id', $runId)
                    ->where('status', 'applied_pending_manifest')->update([
                        'failure_message' => Str::limit($exception->getMessage(), 1000, ''),
                        'updated_at' => now(),
                    ]);
            }
            throw $exception;
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    public function recoverPendingManifest(int $runId): array
    {
        $run = DB::table('accounting_remediation_runs')->where('id', $runId)->first();
        if (!$run) {
            throw new RuntimeException('Remediation run was not found.');
        }

        $lockName = 'salepro_remediation_'.substr(hash('sha256', (string) $run->database_name), 0, 32);
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw new RuntimeException('Another accounting remediation or recovery is already running for this database.');
        }

        try {
            $run = DB::table('accounting_remediation_runs')->where('id', $runId)->first();
            if (!$run) {
                throw new RuntimeException('Remediation run was not found.');
            }

            $payloadJson = $this->durableManifestPayload($run);
            if ($run->status === 'applied') {
                $this->assertManifestFileMatchesRun($run->manifest_path, $run, $payloadJson);
                return ['already_finalized' => true, 'run_uuid' => $run->run_uuid];
            }
            if ($run->status !== 'applied_pending_manifest') {
                throw new RuntimeException('Run is not eligible for manifest recovery.');
            }

            try {
                if ($this->manifests->isValid($run->manifest_path, (string) $run->manifest_sha256)) {
                    $this->assertManifestFileMatchesRun($run->manifest_path, $run, $payloadJson);
                } else {
                    $tempIsValid = $this->manifests->isValid(
                        $run->manifest_temp_path,
                        (string) $run->manifest_sha256
                    );
                    if ($tempIsValid) {
                        $this->assertManifestFileMatchesRun($run->manifest_temp_path, $run, $payloadJson);
                        $tempPath = $run->manifest_temp_path;
                    } else {
                        if ($payloadJson === null) {
                            throw new RuntimeException(
                                'Durable manifest payload is unavailable; this legacy run requires a valid staged or final manifest.'
                            );
                        }
                        $staged = $this->manifests->stageSerialized(
                            (string) $run->manifest_path,
                            $payloadJson,
                            (string) $run->manifest_sha256
                        );
                        $tempPath = $staged['temp_path'];
                        DB::table('accounting_remediation_runs')->where('id', $runId)
                            ->where('status', 'applied_pending_manifest')->update([
                                'manifest_temp_path' => $tempPath,
                                'updated_at' => now(),
                            ]);
                    }

                    $this->manifests->finalize(
                        (string) $tempPath,
                        (string) $run->manifest_path,
                        (string) $run->manifest_sha256
                    );
                    $this->assertManifestFileMatchesRun($run->manifest_path, $run, $payloadJson);
                }

                DB::table('accounting_remediation_runs')->where('id', $runId)
                    ->where('status', 'applied_pending_manifest')->update([
                        'status' => 'applied',
                        'manifest_temp_path' => null,
                        'failure_message' => null,
                        'updated_at' => now(),
                    ]);

                return ['already_finalized' => false, 'run_uuid' => $run->run_uuid];
            } catch (Throwable $exception) {
                DB::table('accounting_remediation_runs')->where('id', $runId)
                    ->where('status', 'applied_pending_manifest')->update([
                        'failure_message' => Str::limit($exception->getMessage(), 1000, ''),
                        'updated_at' => now(),
                    ]);
                throw $exception;
            }
        } catch (Throwable $exception) {
            DB::table('accounting_remediation_runs')->where('id', $runId)
                ->where('status', 'applied_pending_manifest')->update([
                    'failure_message' => Str::limit($exception->getMessage(), 1000, ''),
                    'updated_at' => now(),
                ]);
            throw $exception;
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function durableManifestPayload(object $run): ?string
    {
        $json = $run->manifest_payload_json ?? null;
        if (!is_string($json) || $json === '') {
            return null;
        }
        if (!hash_equals((string) $run->manifest_sha256, hash('sha256', $json))) {
            throw new RuntimeException('Durable remediation manifest payload hash does not match the committed audit record.');
        }

        $payload = $this->decodeJson($json, 'durable manifest payload');
        $this->assertManifestPayloadMatchesRun($payload, $run);

        return $json;
    }

    private function assertManifestFileMatchesRun(?string $path, object $run, ?string $payloadJson): void
    {
        if (!$this->manifests->isValid($path, (string) $run->manifest_sha256)) {
            throw new RuntimeException('Final remediation manifest is missing or does not match its committed hash.');
        }

        $json = file_get_contents((string) $path);
        if (!is_string($json)) {
            throw new RuntimeException('Final remediation manifest could not be read.');
        }
        if ($payloadJson !== null && !hash_equals(hash('sha256', $payloadJson), hash('sha256', $json))) {
            throw new RuntimeException('Final remediation manifest differs from the durable canonical payload.');
        }

        $this->assertManifestPayloadMatchesRun($this->decodeJson($json, 'manifest file'), $run);
    }

    private function assertManifestPayloadMatchesRun(array $payload, object $run): void
    {
        $checks = [
            'run_uuid' => (string) $run->run_uuid,
            'status' => 'applied',
            'plan_hash' => (string) $run->plan_hash,
            'database' => (string) $run->database_name,
        ];
        foreach ($checks as $key => $expected) {
            if (($payload[$key] ?? null) !== $expected) {
                throw new RuntimeException("Remediation manifest {$key} does not match the committed audit record.");
            }
        }

        if (($payload['backup_verification']['sha256'] ?? null) !== (string) $run->backup_sha256
            || ($payload['fingerprint_before']['sha256'] ?? null) !== (string) $run->fingerprint_before
            || ($payload['fingerprint_after']['sha256'] ?? null) !== (string) $run->fingerprint_after) {
            throw new RuntimeException('Remediation manifest hashes do not match the committed audit record.');
        }

        $durableValues = [
            'backup_verification' => $this->decodeJson((string) $run->backup_verification_json, 'backup verification'),
            'selectors' => $this->decodeJson((string) $run->selectors_json, 'selectors'),
            'result' => $this->decodeJson((string) $run->result_json, 'result'),
        ];
        foreach ($durableValues as $key => $expected) {
            if (json_encode($payload[$key] ?? null, JSON_UNESCAPED_SLASHES)
                !== json_encode($expected, JSON_UNESCAPED_SLASHES)) {
                throw new RuntimeException("Remediation manifest {$key} differs from the committed audit record.");
            }
        }
    }

    private function decodeJson(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException("Invalid {$label} JSON in the remediation audit record.", 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid {$label} data in the remediation audit record.");
        }

        return $decoded;
    }

    private function proposedActions(array $before): array
    {
        return [
            'semantic_accounts' => $before['semantic_accounts']['missing'] ?? [],
            'payment_accounts' => $before['payment_accounts']['repairable'] ?? [],
            'sale_returns' => array_values(array_filter($before['sale_returns'] ?? [], fn ($row) => $row['safe_to_remove'] ?? false)),
            'failed_accounting_queue' => $before['failed_accounting_queue'] ?? [],
        ];
    }

    private function assertFinancialInvariants(array $before, array $result, array $fingerprintAfter): void
    {
        $debits = (float) $fingerprintAfter['journal_totals']['debits'];
        $credits = (float) $fingerprintAfter['journal_totals']['credits'];
        if (abs($debits - $credits) > 0.0001) {
            throw new RuntimeException('Remediation would commit an unbalanced general ledger.');
        }

        $afterIntegrity = $result['after']['integrity'] ?? null;
        $beforeIntegrity = $before['integrity'] ?? null;
        foreach (['unbalanced_journal_count', 'orphan_line_count', 'missing_account_line_count'] as $key) {
            if (is_array($afterIntegrity) && is_array($beforeIntegrity)
                && (int) ($afterIntegrity[$key] ?? 0) > (int) ($beforeIntegrity[$key] ?? 0)) {
                throw new RuntimeException("Remediation increased {$key}.");
            }
        }
    }
}
