<?php

namespace App\Jobs;

use App\Services\DeepScanReadinessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class AccountingQueueReadinessProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Cache::put(DeepScanReadinessService::WORKER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addMinutes(10));
    }
}
