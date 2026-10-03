<?php

namespace App\Console\Commands;

use App\Services\AccountingHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingQuickHealthCommand extends Command
{
    protected $signature = 'accounting:health-quick {--json : Emit machine-readable output}';

    protected $description = 'Run the read-only quick accounting health checks (not full release certification).';

    public function handle(AccountingHealthService $health): int
    {
        try {
            $databaseIdentity = $this->assertExpectedDatabase();
        } catch (RuntimeException) {
            $databaseIdentity = $this->databaseIdentity();
            $encoded = json_encode([
                'status' => 'worker_error',
                'worker_error' => 'database_identity_mismatch',
                'worker_database_id' => $databaseIdentity['selected_id'],
                'configured_database_id' => $databaseIdentity['configured_id'],
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $this->output->write($encoded ?: '{}');

            return self::FAILURE;
        }

        $payload = $health->buildQuickPayload();
        $payload['worker_database_id'] = $databaseIdentity['selected_id'];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($this->option('json')) {
            $this->output->write($encoded ?: '{}');
        } else {
            $this->line($encoded ?: '{}');
        }

        return self::SUCCESS;
    }

    private function assertExpectedDatabase(): array
    {
        $identity = $this->databaseIdentity();
        $expected = trim((string) getenv('SALEPRO_HEALTH_EXPECTED_DATABASE'));
        if ($expected === '') {
            return $identity;
        }

        if ($identity['configured_id'] !== $this->databaseIdentifier($expected)
            || $identity['selected_id'] !== $this->databaseIdentifier($expected)) {
            throw new RuntimeException('Accounting health worker database identity mismatch.');
        }

        return $identity;
    }

    private function databaseIdentity(): array
    {
        $connection = (string) config('database.default');
        $configured = (string) config("database.connections.{$connection}.database");
        $selected = (string) (DB::selectOne('SELECT DATABASE() AS database_name')->database_name ?? '');

        return [
            'configured_id' => $this->databaseIdentifier($configured),
            'selected_id' => $this->databaseIdentifier($selected),
        ];
    }

    private function databaseIdentifier(string $database): ?string
    {
        return $database === '' ? null : substr(hash('sha256', $database), 0, 16);
    }
}
