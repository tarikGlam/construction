<?php

namespace App\Console\Commands;

use App\Services\AccountingRemediationCoordinator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RecoverAccountingRemediationManifestCommand extends Command
{
    protected $signature = 'accounting:recover-remediation-manifest
        {--database= : Explicit target database name}
        {--run-id= : Applied-pending-manifest remediation run ID}';

    protected $description = 'Finalize a staged external manifest for a financially committed remediation run.';

    public function handle(AccountingRemediationCoordinator $coordinator): int
    {
        $database = trim((string) $this->option('database'));
        $runId = (int) $this->option('run-id');
        if ($database === '' || $runId < 1) {
            $this->error('Explicit --database= and --run-id= values are required.');
            return self::FAILURE;
        }

        $original = DB::getDefaultConnection();
        $base = config("database.connections.{$original}");
        if (!is_array($base) || ($base['driver'] ?? null) !== 'mysql') {
            $this->error('The configured application database connection is not MySQL.');
            return self::FAILURE;
        }
        $connection = 'remediation_manifest_recovery';
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
            $result = $coordinator->recoverPendingManifest($runId);
            $message = ($result['already_finalized'] ?? false)
                ? "Remediation run {$runId} manifest was already finalized and verified."
                : "Remediation run {$runId} manifest finalized and verified.";
            $this->info($message);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Manifest recovery failed safely: '.$exception->getMessage());
            return self::FAILURE;
        } finally {
            DB::setDefaultConnection($original);
            if ($switched) {
                DB::purge($connection);
            }
        }
    }
}
