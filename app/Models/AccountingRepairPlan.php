<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingRepairPlan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_ids' => 'array', 'expected_state_fingerprints' => 'array', 'proposed_mutations' => 'array',
        'expected_effect' => 'array', 'preconditions' => 'array', 'backup_confirmation_required' => 'boolean',
        'backup_confirmed' => 'boolean', 'previewed_at' => 'datetime', 'expires_at' => 'datetime',
        'approved_at' => 'datetime', 'executed_at' => 'datetime', 'result' => 'array',
        'corrective_journal_ids' => 'array', 'before_fingerprints' => 'array', 'after_fingerprints' => 'array',
        'verification_evidence' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'plan_key';
    }
}
