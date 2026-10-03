<?php

namespace App\Jobs;

use App\Models\AccountingDiagnosticScan;
use App\Services\AccountingDiagnosticDeepScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessAccountingDiagnosticScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(public int $scanId) {}

    public function handle(AccountingDiagnosticDeepScanService $service): void
    {
        if (!config('accounting.health_advanced_enabled', false)
            || !config('accounting.deep_scan_enabled', false)) {
            return;
        }

        $scan = AccountingDiagnosticScan::find($this->scanId);
        if (!$scan || in_array($scan->status, ['completed', 'completed_with_warnings', 'cancelled', 'superseded'], true)) return;
        $scan = $service->processChunk($scan);
        // processChunk's row lock is the concurrency guard. Dispatching only after
        // its checkpoint transaction commits also works with the sync driver.
        if (in_array($scan->status, ['created', 'queued', 'running'], true)) self::dispatch($scan->id);
    }

    public function failed(Throwable $exception): void
    {
        AccountingDiagnosticScan::whereKey($this->scanId)->whereNotIn('status', ['completed', 'cancelled'])
            ->update(['status' => 'failed', 'consistency_status' => 'partial', 'failed_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'failure_summary' => 'The diagnostic worker exhausted its retries. Resume is available when its checkpoint is compatible.']);
    }
}
