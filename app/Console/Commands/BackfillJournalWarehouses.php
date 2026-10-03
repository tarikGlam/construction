<?php

namespace App\Console\Commands;

use App\Services\JournalWarehouseBackfillService;
use Illuminate\Console\Command;

class BackfillJournalWarehouses extends Command
{
    protected $signature = 'accounting:backfill-journal-warehouses
        {--write : Persist deterministic attributions}
        {--force : Permit writes outside the testing environment}';

    protected $description = 'Audit or backfill authoritative warehouse attribution for journal entries';

    public function handle(JournalWarehouseBackfillService $backfill): int
    {
        $write = (bool) $this->option('write');
        if ($write && !app()->environment('testing') && !$this->option('force')) {
            $this->error('Refusing to mutate a non-testing database without --force.');
            return self::FAILURE;
        }

        $stats = $backfill->run($write);
        $this->table(['Classification', 'Count'], [
            ['Total journals', $stats['total']],
            ['Attributed', $stats['attributed']],
            ['Intentionally global', $stats['intentionally_global']],
            ['Unresolved', $stats['unresolved']],
            ['Rows updated', $stats['updated']],
        ]);

        if ($stats['unresolved_groups']) {
            $this->warn('Unresolved journal groups:');
            $this->table(['Source / event / reason', 'Count'], collect($stats['unresolved_groups'])
                ->map(fn (int $count, string $group) => [$group, $count])->values()->all());
        }

        return self::SUCCESS;
    }
}
