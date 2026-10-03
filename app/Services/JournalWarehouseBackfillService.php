<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class JournalWarehouseBackfillService
{
    public function __construct(private JournalWarehouseResolver $resolver)
    {
    }

    /**
     * @param bool $write
     * @param array<int>|null $ids
     * @return array{total:int, attributed:int, intentionally_global:int, unresolved:int, updated:int, unresolved_groups:array<string, int>}
     */
    public function run(bool $write = false, ?array $ids = null): array
    {
        $stats = [
            'total' => 0,
            'attributed' => 0,
            'intentionally_global' => 0,
            'unresolved' => 0,
            'updated' => 0,
            'unresolved_groups' => [],
        ];

        $query = DB::table('journal_entries')->orderBy('id');
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $query->chunkById(500, function ($journals) use (&$stats, $write): void {
            foreach ($journals as $journal) {
                $stats['total']++;
                $attribution = $this->resolver->resolve(
                    $journal->source_type,
                    $journal->source_id ? (int) $journal->source_id : null,
                    $journal->source_subtype ?? null,
                    $journal->event_type ?? null,
                    $journal->related_journal_entry_id ?? null,
                );

                if ($attribution->warehouseId) {
                    $stats['attributed']++;
                    if ($write && (int) ($journal->warehouse_id ?? 0) !== $attribution->warehouseId) {
                        DB::table('journal_entries')->where('id', $journal->id)->update([
                            'warehouse_id' => $attribution->warehouseId,
                        ]);
                        $stats['updated']++;
                    }
                    continue;
                }

                if ($attribution->classification === 'global') {
                    $stats['intentionally_global']++;
                    if ($write && $journal->warehouse_id !== null) {
                        DB::table('journal_entries')->where('id', $journal->id)->update([
                            'warehouse_id' => null,
                        ]);
                        $stats['updated']++;
                    }
                    continue;
                }

                $stats['unresolved']++;
                $key = sprintf(
                    '%s | %s | %s',
                    $journal->source_type ?: '(none)',
                    $journal->event_type ?: '(none)',
                    $attribution->reason,
                );
                $stats['unresolved_groups'][$key] = ($stats['unresolved_groups'][$key] ?? 0) + 1;
            }
        }, 'id');

        ksort($stats['unresolved_groups']);

        return $stats;
    }
}
