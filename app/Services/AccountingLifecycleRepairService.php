<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AccountingLifecycleRepairService
{
    public function repair(): array
    {
        $integrity = app(JournalSourceIntegrityService::class);
        $accounting = app(AccountingService::class);
        $repaired = 0;
        $failed = 0;

        // Drafts are operational work-in-progress and must not affect the GL.
        Sale::query()->where('sale_status', 3)->each(function (Sale $sale) use ($accounting, &$repaired, &$failed) {
            $result = $accounting->reverseTransaction(Sale::class, (int) $sale->id, '_reversed');
            if ($result->success) {
                if (Schema::hasColumn($sale->getTable(), 'accounting_status')) {
                    $sale->accounting_status = 'pending';
                    $sale->saveQuietly();
                }
                if ($result->isPosted()) {
                    $repaired++;
                }
            } else {
                $failed++;
            }
        });

        $journals = JournalEntry::query()
            ->whereNotNull('source_type')
            ->whereNull('related_journal_entry_id')
            ->get();

        foreach ($journals as $journal) {
            $classification = $integrity->classify($journal);
            if (!$integrity->isFailure($classification)) {
                continue;
            }

            if (!in_array($classification['reason'], [
                'source_row_missing',
                'soft_deleted_source_without_reversal',
            ], true)) {
                $failed++;
                continue;
            }

            $result = $accounting->reverseTransaction(
                (string) $journal->source_type,
                (int) $journal->source_id,
                '_deleted'
            );

            if ($result->success) {
                $repaired++;
            } else {
                $failed++;
                Log::warning('Accounting lifecycle repair could not reverse an orphaned journal.', [
                    'journal_id' => $journal->id,
                    'source_type' => $journal->source_type,
                    'source_id' => $journal->source_id,
                    'error' => $result->error,
                ]);
            }
        }

        return ['repaired' => $repaired, 'failed' => $failed];
    }
}
