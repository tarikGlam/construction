<?php

namespace App\Console\Commands;

use App\Services\PaymentAccountMappingRepairService;
use App\Services\AccountingRemediationCoordinator;
use App\Services\DatabaseBackupVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RepairPaymentAccountMappingsCommand extends Command
{
    protected $signature = 'accounting:repair-payment-account-mappings
        {--database= : Explicit target database name (required)}
        {--apply : Create mappings for unambiguously missing active payment accounts}
        {--backup= : SQL backup of the intended target database required with --apply}
        {--backup-sha256= : Approved SHA-256 of the SQL backup required with --apply}
        {--operator-id= : SalePro operator user ID required with --apply}
        {--manifest= : Relative storage/app path for the apply manifest}';

    protected $description = 'Audit or repair missing legacy payment-account mappings without overwriting existing mappings.';

    public function handle(
        PaymentAccountMappingRepairService $repair,
        DatabaseBackupVerificationService $backups,
        AccountingRemediationCoordinator $coordinator
    ): int
    {
        $database = trim((string) $this->option('database'));
        if ($database === '') {
            $this->error('Refusing to run without an explicit --database= target.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $backup = trim((string) $this->option('backup'));
        $backupSha256 = trim((string) $this->option('backup-sha256'));
        $operatorId = (int) $this->option('operator-id');
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
            $before = $repair->inspect();
            if ($apply) {
                $backupVerification = $backups->verify($backup, $backupSha256, $database);
                $manifestPath = trim((string) $this->option('manifest'))
                    ?: 'accounting-remediation/payment-account-mappings-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $database).'-'.now()->format('YmdHis').'.json';
                $execution = $coordinator->execute(
                    'payment-account-mappings',
                    $database,
                    $backupVerification,
                    [],
                    ['payment_accounts' => $before],
                    function () use ($repair, $before) {
                        $changes = $repair->apply();
                        return ['before' => ['payment_accounts' => $before], 'changes' => $changes, 'after' => ['payment_accounts' => $repair->inspect()]];
                    },
                    $manifestPath,
                    $operatorId
                );
                $result = $execution['result']['changes'];
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

        if (!$result['authoritative']) {
            $this->error('Double-entry accounting is not authoritative; no mappings were changed.');
            return self::FAILURE;
        }

        foreach ($result['ambiguous'] as $item) {
            $this->warn("AMBIGUOUS account {$item['account_id']} [{$item['account_name']}]: {$item['review_reason']}");
        }

        foreach ($result['invalid'] as $item) {
            $this->warn("INVALID account {$item['account_id']} [{$item['account_name']}] has mapping {$item['mapping_id']}; left unchanged.");
        }

        foreach ($result['missing'] as $item) {
            $disposition = $item['safe_to_create'] ? 'CREATE' : 'REVIEW';
            $this->line("{$disposition} account {$item['account_id']} [{$item['account_name']}] -> {$item['expected']['code']} [{$item['expected']['name']}]");
        }

        if ($apply && !$result['schema_safe']) {
                $this->error('Repair refused: required AUTO_INCREMENT metadata is missing from: ' . implode(', ', $result['schema_issues']) . '.');
                $this->error('Restore the database schema before creating accounting records. No mappings were changed.');
                return self::FAILURE;
        }

        foreach ($result['repaired'] ?? [] as $item) {
            $this->info("CREATED account {$item['account_id']} -> {$item['accounting_code']} [{$item['accounting_name']}]");
        }
        foreach ($result['errors'] ?? [] as $error) $this->error($error);

        if ($apply && empty($result['errors'])) {
            $this->info('Durable remediation run: '.$execution['run_uuid']);
            $this->info('Manifest: '.($execution['manifest']['path'] ?? 'existing applied run'));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d missing, %d invalid existing, %d changed.',
            $apply ? 'Apply complete' : 'Dry run complete',
            $result['missing_count'],
            $result['invalid_count'],
            $result['repaired_count'] ?? 0
        ));

        if (!$apply && $result['missing_count'] > 0) {
            $this->comment('Run again with --apply to create only the missing mappings.');
        }

        return !empty($result['errors']) ? self::FAILURE : self::SUCCESS;
    }

}
