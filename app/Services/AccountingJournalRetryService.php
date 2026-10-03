<?php

namespace App\Services;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class AccountingJournalRetryService
{
    public function classify(string $sourceType, int $sourceId): array
    {
        $journals = JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)->with('lines')->orderBy('id')->get();
        if ($journals->isEmpty()) {
            return ['status' => 'missing', 'journals' => []];
        }

        $reversedIds = $journals->pluck('related_journal_entry_id')->filter()->map(fn ($id) => (int) $id)->all();
        $active = $journals->filter(fn (JournalEntry $journal) =>
            !$journal->related_journal_entry_id && !in_array((int) $journal->id, $reversedIds, true)
        )->values();

        if ($active->count() > 1) {
            return [
                'status' => 'duplicate',
                'journals' => $journals->pluck('id')->all(),
                'active_journals' => $active->pluck('id')->all(),
                'reason' => 'More than one unreversed journal exists for the source.',
            ];
        }

        if ($active->isEmpty()) {
            return [
                'status' => 'reversed',
                'journals' => $journals->pluck('id')->all(),
                'reason' => 'The source has journal history, but every posting has been reversed.',
            ];
        }

        $journal = $active->first();
        $validation = $this->validate($journal);
        return [
            'status' => $validation['valid'] ? 'already_posted_valid' : 'existing_invalid',
            'journals' => $journals->pluck('id')->all(),
            'active_journals' => [$journal->id],
            'reason' => $validation['reason'],
            'debits' => $validation['debits'],
            'credits' => $validation['credits'],
        ];
    }

    private function validate(JournalEntry $journal): array
    {
        $debits = (float) $journal->lines->sum('debit');
        $credits = (float) $journal->lines->sum('credit');
        if ($journal->lines->isEmpty()) {
            return compact('debits', 'credits') + ['valid' => false, 'reason' => 'The journal has no lines.'];
        }
        if (round($debits, 4) !== round($credits, 4)) {
            return compact('debits', 'credits') + ['valid' => false, 'reason' => 'The journal is unbalanced.'];
        }
        $missingAccounts = DB::table('journal_lines as jl')
            ->leftJoin('accounting_accounts as aa', 'aa.id', '=', 'jl.accounting_account_id')
            ->where('jl.journal_entry_id', $journal->id)->whereNull('aa.id')->count();
        if ($missingAccounts > 0) {
            return compact('debits', 'credits') + ['valid' => false, 'reason' => 'The journal contains a missing ledger account.'];
        }

        return compact('debits', 'credits') + ['valid' => true, 'reason' => 'One balanced unreversed journal matches the source.'];
    }
}
