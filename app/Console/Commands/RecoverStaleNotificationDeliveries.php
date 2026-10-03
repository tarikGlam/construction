<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationChannelJob;
use App\Models\NotificationDelivery;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RecoverStaleNotificationDeliveries extends Command
{
    protected $signature = 'notifications:recover-stale {--minutes=15}';
    protected $description = 'Recover and re-dispatch notification deliveries stuck in processing state due to worker crashes';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = Carbon::now()->subMinutes($minutes);

        $count = 0;
        NotificationDelivery::where('status', 'processing')
            ->where('updated_at', '<', $threshold)
            ->where('attempts', '<', 3)
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$count, $threshold) {
                foreach ($records as $record) {
                    // Conditional state transition is an atomic recovery claim.
                    $reset = NotificationDelivery::where('id', $record->id)
                        ->where('status', 'processing')
                        ->where('updated_at', '<', $threshold)
                        ->update([
                            'status'        => 'pending',
                            'error_summary' => 'Recovered from stale processing worker crash',
                            'updated_at'    => now(),
                        ]);

                    if ($reset) {
                        SendNotificationChannelJob::dispatch($record->id);
                        $count++;
                    }
                }
            });

        $this->info($count === 0
            ? 'No stale notification deliveries found.'
            : "Successfully recovered and re-queued {$count} stale notification deliveries.");
        return 0;
    }
}
