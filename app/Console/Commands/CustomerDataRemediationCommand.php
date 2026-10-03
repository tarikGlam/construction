<?php

namespace App\Console\Commands;

use App\Services\CustomerDataRemediationService;
use App\Services\AccountingRemediationCoordinator;
use App\Services\DatabaseBackupVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CustomerDataRemediationCommand extends Command
{
    protected $signature = 'accounting:remediate-customer-data
        {--database= : Explicit target database name (required)}
        {--apply : Apply only the actions marked automatically repairable}
        {--backup= : SQL backup of the intended target database required with --apply}
        {--backup-sha256= : Approved SHA-256 of the SQL backup required with --apply}
        {--operator-id= : SalePro operator user ID required with --apply}
        {--approve-return=* : Return IDs whose safe zero-value rows may be removed}
        {--approve-queue=* : Queue IDs whose classified safe action may be applied}
        {--manifest= : Relative storage/app manifest path}';

    protected $description = 'Dry-run or apply auditable semantic, payment-account, malformed-return, and failed-queue remediation.';

    public function handle(
        CustomerDataRemediationService $service,
        DatabaseBackupVerificationService $backups,
        AccountingRemediationCoordinator $coordinator
    ): int
    {
        $database = trim((string) $this->option('database'));
        $apply = (bool) $this->option('apply');
        $backup = trim((string) $this->option('backup'));
        $backupSha256 = trim((string) $this->option('backup-sha256'));
        $operatorId = (int) $this->option('operator-id');
        if ($database === '') {
            $this->error('Refusing to run without an explicit --database= target.');
            return self::FAILURE;
        }
        if ($apply && $operatorId < 1) {
            $this->error('Apply refused: provide the accountable SalePro user with --operator-id=.');
            return self::FAILURE;
        }

        $original = DB::getDefaultConnection();
        $connection = 'customer_remediation_target';
        $base = config("database.connections.{$original}");
        if (!is_array($base) || ($base['driver'] ?? null) !== 'mysql') {
            $this->error('The configured application database connection is not MySQL.');
            return self::FAILURE;
        }
        $switched = DB::connection($original)->getDatabaseName() !== $database;
        if ($switched) {
            $base['database'] = $database;
            config(["database.connections.{$connection}" => $base]);
            DB::purge($connection);
            DB::setDefaultConnection($connection);
        }

        try {
            if (DB::connection()->getDatabaseName() !== $database) {
                throw new RuntimeException('Target database safety check failed.');
            }
            $before = $service->inspect();
            if ($apply) {
                $backupVerification = $backups->verify($backup, $backupSha256, $database);
                $manifestPath = trim((string) $this->option('manifest'))
                    ?: 'accounting-remediation/customer-data-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $database).'-'.now()->format('YmdHis').'.json';
                $selectors = [
                    'approved_return_ids' => array_map('intval', (array) $this->option('approve-return')),
                    'approved_queue_ids' => array_map('intval', (array) $this->option('approve-queue')),
                ];
                $execution = $coordinator->execute(
                    'customer-data',
                    $database,
                    $backupVerification,
                    $selectors,
                    $before,
                    fn () => $service->apply($selectors['approved_return_ids'], $selectors['approved_queue_ids']),
                    $manifestPath,
                    $operatorId
                );
                $result = $execution['result'];
            } else {
                $result = $before;
            }
        } catch (Throwable $e) {
            report($e);
            $this->error('Remediation failed safely: '.$e->getMessage());
            return self::FAILURE;
        } finally {
            DB::setDefaultConnection($original);
            if ($switched) {
                DB::purge($connection);
            }
        }

        $payload = $apply ? ($result['before'] ?? $before) : $result;
        $this->line(json_encode($apply ? $result : ['dry_run' => true] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($apply) {
            $this->info('Durable remediation run: '.$execution['run_uuid']);
            $this->info('Manifest: '.($execution['manifest']['path'] ?? 'existing applied run'));
        } else {
            $this->comment('Dry run only. No database rows were changed.');
        }

        return !empty($payload['semantic_accounts']['conflicts']) ? self::FAILURE : self::SUCCESS;
    }
}
