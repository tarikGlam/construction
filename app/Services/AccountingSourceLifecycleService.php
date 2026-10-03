<?php

namespace App\Services;

use App\Models\AccountingSourceLifecycleEvent;
use App\Models\JournalEntry;
use Illuminate\Support\Str;

class AccountingSourceLifecycleService
{
    public function record(string $sourceType, int $sourceId, string $action, string $status,
        JournalEntry $original, ?JournalEntry $reversal, ?string $relatedType, ?int $relatedId, ?int $actorId): AccountingSourceLifecycleEvent
    {
        $evidence = ['source_type' => $sourceType, 'source_id' => $sourceId, 'action' => $action,
            'status' => $status, 'original_journal_entry_id' => $original->id,
            'reversal_journal_entry_id' => $reversal?->id, 'related_source_type' => $relatedType,
            'related_source_id' => $relatedId];
        return AccountingSourceLifecycleEvent::create($evidence + ['event_key' => (string) Str::uuid(),
            'actor_id' => $actorId, 'evidence' => $evidence,
            'evidence_hash' => hash('sha256', json_encode($evidence)), 'occurred_at' => now()]);
    }

    public function deterministicEvidence(JournalEntry $journal): ?AccountingSourceLifecycleEvent
    {
        $event = AccountingSourceLifecycleEvent::where('source_type', $journal->source_type)
            ->where('source_id', $journal->source_id)->where('original_journal_entry_id', $journal->id)
            ->whereIn('action', ['cancelled', 'reversed', 'supported_delete'])
            ->where('status', 'completed')->latest('id')->first();
        if (!$event) return null;

        $evidence = ['source_type' => $event->source_type, 'source_id' => (int) $event->source_id,
            'action' => $event->action, 'status' => $event->status,
            'original_journal_entry_id' => (int) $event->original_journal_entry_id,
            'reversal_journal_entry_id' => $event->reversal_journal_entry_id ? (int) $event->reversal_journal_entry_id : null,
            'related_source_type' => $event->related_source_type,
            'related_source_id' => $event->related_source_id ? (int) $event->related_source_id : null];

        return hash_equals((string) $event->evidence_hash, hash('sha256', json_encode($evidence))) ? $event : null;
    }
}
