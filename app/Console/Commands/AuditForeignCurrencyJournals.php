<?php

namespace App\Console\Commands;

use App\Models\Currency;
use App\Models\JournalEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditForeignCurrencyJournals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounting:audit-foreign-currency-journals
                            {--entry-id= : Filter audit to a specific journal entry ID}
                            {--from= : Filter by start entry date (YYYY-MM-DD)}
                            {--to= : Filter by end entry date (YYYY-MM-DD)}
                            {--reference= : Filter by reference number}
                            {--cutover= : Cutover date (YYYY-MM-DD) before which entries are marked pre-cutover}
                            {--format=table : Output format (table or json)}
                            {--chunk=100 : Chunk size for bounded processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit historical foreign-currency journal entries and certify zero database writes.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $format = strtolower((string) ($this->option('format') ?: 'table'));
        $chunkSize = max(1, (int) ($this->option('chunk') ?: 100));
        $entryIdFilter = $this->option('entry-id');
        $fromDate = $this->option('from');
        $toDate = $this->option('to');
        $referenceFilter = $this->option('reference');
        $cutoverDate = $this->option('cutover');

        // 1. Guard against any database write queries
        DB::listen(function ($query) {
            $sql = trim(strtoupper($query->sql));
            foreach (['INSERT', 'UPDATE', 'DELETE', 'ALTER', 'DROP', 'TRUNCATE', 'REPLACE'] as $writeKeyword) {
                if (str_starts_with($sql, $writeKeyword)) {
                    throw new \RuntimeException("Read-only violation: Write query intercepted during audit: {$query->sql}");
                }
            }
        });

        // 2. Pre-audit database fingerprint & checksums (bounded O(1) index queries)
        $preEntryCount = DB::table('journal_entries')->count();
        $preLineCount = DB::table('journal_lines')->count();
        $preEntryHash = md5("count:{$preEntryCount},max_id:" . (DB::table('journal_entries')->max('id') ?? 0) . ",max_upd:" . (DB::table('journal_entries')->max('updated_at') ?? 'none'));
        $preLineHash = md5("count:{$preLineCount},max_id:" . (DB::table('journal_lines')->max('id') ?? 0) . ",max_upd:" . (DB::table('journal_lines')->max('updated_at') ?? 'none'));

        // 3. Base currency identification
        $generalSetting = DB::table('general_settings')->first();
        $baseCurrencyId = $generalSetting->currency ?? 1;
        $baseCurrency = Currency::find($baseCurrencyId) ?? Currency::first();
        $baseCurrencyCode = $baseCurrency?->code ?? 'USD';

        // 4. Build bounded chunked query
        $query = JournalEntry::with('lines');
        if ($entryIdFilter) {
            $query->where('id', $entryIdFilter);
        }
        if ($fromDate) {
            $query->whereDate('entry_date', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('entry_date', '<=', $toDate);
        }
        if ($referenceFilter) {
            $query->where('reference_no', 'like', "%{$referenceFilter}%");
        }

        $totalEntriesAudited = 0;
        $foreignEntriesCount = 0;
        $discrepancies = [];
        $classificationCounts = [
            'correct' => 0,
            'likely overstated' => 0,
            'likely understated' => 0,
            'unbalanced' => 0,
            'reversed' => 0,
            'reposted' => 0,
            'pre-cutover' => 0,
            'voided/deleted source' => 0,
            'missing source' => 0,
            'missing rate' => 0,
            'invalid rate' => 0,
            'unsupported source' => 0,
            'ambiguous' => 0,
        ];

        $supportedSources = [
            'App\Models\Sale',
            'App\Models\Purchase',
            'App\Models\Payment',
            'App\Models\Returns',
            'App\Models\ReturnPurchase',
            'App\Models\Expense',
            'App\Models\Payroll',
            'App\Models\MoneyTransfer',
            'App\Models\Deposit',
            'App\Models\SaleExchange',
            'App\Models\AccountingConfig',
            'activation',
        ];

        $severityOrder = [
            'unbalanced',
            'missing source',
            'voided/deleted source',
            'invalid rate',
            'missing rate',
            'unsupported source',
            'reversed',
            'reposted',
            'pre-cutover',
            'likely overstated',
            'likely understated',
            'ambiguous',
        ];

        $query->chunkById($chunkSize, function ($entries) use (
            &$totalEntriesAudited,
            &$foreignEntriesCount,
            &$discrepancies,
            &$classificationCounts,
            $baseCurrencyId,
            $cutoverDate,
            $supportedSources,
            $severityOrder,
            $entryIdFilter
        ) {
            $entryIds = $entries->pluck('id')->all();
            $reversedIds = JournalEntry::whereIn('related_journal_entry_id', $entryIds)
                ->pluck('related_journal_entry_id')
                ->flip()
                ->all();

            foreach ($entries as $entry) {
                $totalEntriesAudited++;
                $classifications = [];

                // Check balance
                $totalDebit = round((float) $entry->lines->sum('debit'), 4);
                $totalCredit = round((float) $entry->lines->sum('credit'), 4);
                $isUnbalanced = abs($totalDebit - $totalCredit) > 0.0001;
                if ($isUnbalanced) {
                    $classifications[] = 'unbalanced';
                }

                // Check source existence & soft deletion safely
                $source = null;
                $isTrashed = false;
                if ($entry->source_type && $entry->source_id) {
                    if ($entry->source_type === 'activation') {
                        $source = \App\Models\AccountingConfig::find($entry->source_id);
                    } elseif (class_exists($entry->source_type)) {
                        try {
                            if (method_exists($entry->source_type, 'withTrashed')) {
                                $source = ($entry->source_type)::withTrashed()->find($entry->source_id);
                                if ($source && method_exists($source, 'trashed') && $source->trashed()) {
                                    $isTrashed = true;
                                }
                            } else {
                                $source = ($entry->source_type)::find($entry->source_id);
                            }
                        } catch (\Throwable) {
                            $source = null;
                        }
                    }
                }

                if ($isTrashed || ($source && method_exists($source, 'trashed') && $source->trashed())) {
                    $classifications[] = 'voided/deleted source';
                } elseif (!$source && $entry->source_type) {
                    $classifications[] = 'missing source';
                }

                // Check unsupported source
                if ($source) {
                    $sourceClass = get_class($source);
                    if (!in_array($sourceClass, $supportedSources, true) && !in_array($entry->source_type, $supportedSources, true)) {
                        $classifications[] = 'unsupported source';
                    }
                }

                // Check reversal status
                $isReversed = isset($reversedIds[$entry->id])
                    || $entry->related_journal_entry_id !== null
                    || in_array(strtolower((string) $entry->event_type), ['reversed', 'reversal'], true);
                if ($isReversed) {
                    $classifications[] = 'reversed';
                }

                // Check reposted status
                if (in_array(strtolower((string) $entry->event_type), ['updated', 'reposted'], true)) {
                    $classifications[] = 'reposted';
                }

                // Check pre-cutover
                if ($cutoverDate && $entry->entry_date && $entry->entry_date->format('Y-m-d') < $cutoverDate) {
                    $classifications[] = 'pre-cutover';
                }

                // Currency and rate inspection
                $currencyId = $source?->currency_id ?? null;
                $exchangeRateRaw = $source?->exchange_rate ?? null;

                $isForeign = ($currencyId && (int) $currencyId !== (int) $baseCurrencyId)
                    || ($exchangeRateRaw !== null && abs((float) $exchangeRateRaw - 1.0) > 0.000001);

                if ($currencyId && (int) $currencyId !== (int) $baseCurrencyId && $exchangeRateRaw === null) {
                    $classifications[] = 'missing rate';
                } elseif ($exchangeRateRaw !== null && (!is_numeric($exchangeRateRaw) || (float) $exchangeRateRaw <= 0)) {
                    $classifications[] = 'invalid rate';
                }

                if ($isForeign || $entryIdFilter) {
                    if ($isForeign) {
                        $foreignEntriesCount++;
                    }

                    $sourceCurrency = $currencyId ? Currency::find($currencyId) : null;
                    $currencyCode = $sourceCurrency?->code ?? ($isForeign ? 'FOREIGN' : 'BASE');

                    $txAmount = null;
                    if ($source) {
                        if (isset($source->grand_total)) {
                            $txAmount = (float) $source->grand_total;
                        } elseif (isset($source->amount)) {
                            $txAmount = (float) $source->amount;
                        } elseif (isset($source->total_price)) {
                            $txAmount = (float) $source->total_price;
                        } elseif (isset($source->total_cost)) {
                            $txAmount = (float) $source->total_cost;
                        }
                    }

                    $exchangeRate = ($exchangeRateRaw !== null && is_numeric($exchangeRateRaw) && (float) $exchangeRateRaw > 0)
                        ? (float) $exchangeRateRaw
                        : null;

                    $expectedBase = null;
                    $variance = null;

                    if ($txAmount !== null && $exchangeRate !== null) {
                        $expectedBase = round($txAmount / $exchangeRate, 4);
                        $variance = round($totalDebit - $expectedBase, 4);

                        if ($variance > 0.01) {
                            $classifications[] = 'likely overstated';
                        } elseif ($variance < -0.01) {
                            $classifications[] = 'likely understated';
                        }
                    } elseif ($isForeign && $txAmount === null && !in_array('missing source', $classifications, true) && !in_array('voided/deleted source', $classifications, true)) {
                        $classifications[] = 'ambiguous';
                    }

                    // Compound validation: determine primary classification
                    $primary = 'correct';
                    foreach ($severityOrder as $candidate) {
                        if (in_array($candidate, $classifications, true)) {
                            $primary = $candidate;
                            break;
                        }
                    }

                    if (empty($classifications)) {
                        $classifications[] = 'correct';
                        $primary = 'correct';
                    }

                    $classificationCounts[$primary] = ($classificationCounts[$primary] ?? 0) + 1;

                    if ($primary !== 'correct') {
                        $statusStr = implode(', ', $classifications);
                        $discrepancies[] = [
                            'entry_id' => $entry->id,
                            'reference_no' => $entry->reference_no,
                            'entry_date' => $entry->entry_date ? $entry->entry_date->format('Y-m-d') : 'N/A',
                            'source_type' => class_basename($entry->source_type ?: 'Unknown'),
                            'source_id' => $entry->source_id ?? 0,
                            'currency' => $currencyCode,
                            'exchange_rate' => $exchangeRate,
                            'tx_amount' => $txAmount,
                            'recorded_base' => $totalDebit,
                            'expected_base' => $expectedBase,
                            'variance' => $variance,
                            'classification' => $primary,
                            'classifications' => $classifications,
                            'status' => $statusStr,
                        ];
                    }
                } elseif ($isUnbalanced) {
                    $classificationCounts['unbalanced'] = ($classificationCounts['unbalanced'] ?? 0) + 1;
                    $discrepancies[] = [
                        'entry_id' => $entry->id,
                        'reference_no' => $entry->reference_no,
                        'entry_date' => $entry->entry_date ? $entry->entry_date->format('Y-m-d') : 'N/A',
                        'source_type' => class_basename($entry->source_type ?: 'Unknown'),
                        'source_id' => $entry->source_id ?? 0,
                        'currency' => 'BASE',
                        'exchange_rate' => 1.0,
                        'tx_amount' => $totalDebit,
                        'recorded_base' => $totalDebit,
                        'expected_base' => $totalCredit,
                        'variance' => round($totalDebit - $totalCredit, 4),
                        'classification' => 'unbalanced',
                        'classifications' => ['unbalanced'],
                        'status' => 'unbalanced',
                    ];
                } else {
                    $classificationCounts['correct'] = ($classificationCounts['correct'] ?? 0) + 1;
                }
            }
        }, 'id');

        // 5. Post-audit database fingerprint & checksums verification
        $postEntryCount = DB::table('journal_entries')->count();
        $postLineCount = DB::table('journal_lines')->count();
        $postEntryHash = md5("count:{$postEntryCount},max_id:" . (DB::table('journal_entries')->max('id') ?? 0) . ",max_upd:" . (DB::table('journal_entries')->max('updated_at') ?? 'none'));
        $postLineHash = md5("count:{$postLineCount},max_id:" . (DB::table('journal_lines')->max('id') ?? 0) . ",max_upd:" . (DB::table('journal_lines')->max('updated_at') ?? 'none'));

        if (
            $preEntryCount !== $postEntryCount ||
            $preLineCount !== $postLineCount ||
            $preEntryHash !== $postEntryHash ||
            $preLineHash !== $postLineHash
        ) {
            throw new \RuntimeException('Read-only violation: Database modified during audit execution.');
        }

        // 6. Format and display output
        if ($format === 'json') {
            $this->line(json_encode([
                'summary' => [
                    'total_entries_audited' => $totalEntriesAudited,
                    'foreign_entries_found' => $foreignEntriesCount,
                    'discrepancies_count' => count($discrepancies),
                    'classifications_summary' => $classificationCounts,
                    'zero_writes_verified' => true,
                    'entries_checksum' => $postEntryHash,
                    'lines_checksum' => $postLineHash,
                ],
                'discrepancies' => $discrepancies,
            ], JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('=== Foreign Currency Journal Audit ===');
        $this->info("Base Currency: {$baseCurrencyCode} (ID: {$baseCurrencyId})");
        $this->info("Total Journal Entries Audited: {$totalEntriesAudited}");
        $this->info("Foreign Currency Entries: {$foreignEntriesCount}");
        $this->info("Discrepancies Found: " . count($discrepancies));

        if (count($discrepancies) > 0) {
            $this->table(
                ['Entry ID', 'Ref', 'Date', 'Source', 'Cur', 'Rate', 'Tx Amount', 'Recorded Base', 'Expected Base', 'Variance', 'Status'],
                array_map(fn ($d) => [
                    $d['entry_id'],
                    $d['reference_no'],
                    $d['entry_date'],
                    "{$d['source_type']} #{$d['source_id']}",
                    $d['currency'],
                    $d['exchange_rate'] ?? 'N/A',
                    $d['tx_amount'] !== null ? number_format($d['tx_amount'], 2) : 'N/A',
                    number_format($d['recorded_base'], 2),
                    $d['expected_base'] !== null ? number_format($d['expected_base'], 2) : 'N/A',
                    $d['variance'] !== null ? number_format($d['variance'], 2) : 'N/A',
                    $d['status'],
                ], $discrepancies)
            );
        } else {
            $this->info('All foreign currency journal entries are correctly normalized to base currency.');
        }

        $this->info("Database verification: 0 writes executed. Checksum verified (entries: {$postEntryHash}, lines: {$postLineHash}).");

        return 0;
    }
}
