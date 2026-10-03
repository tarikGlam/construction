<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingDiagnosticScan extends Model
{
    protected $guarded = [];
    protected $casts = ['results' => 'array', 'watermarks' => 'array', 'checkpoints' => 'array', 'fingerprints' => 'array',
        'progress_details' => 'array', 'dataset_as_of' => 'datetime', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime',
        'paused_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime',
        'superseded_at' => 'datetime', 'cancellation_requested_at' => 'datetime'];

    public function getRouteKeyName(): string { return 'scan_key'; }
}
