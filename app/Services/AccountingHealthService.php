<?php

namespace App\Services;

use App\Models\AccountingSyncQueue;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class AccountingHealthService
{
    public function __construct(private HealthCheckProcessRunner $processRunner)
    {
    }

    private const GROUPS = [
        'financial_records' => [
            'title_key' => 'accounting_health_group_financial_records',
            'description_key' => 'accounting_health_group_financial_records_description',
            'details_id' => 'financial-records-details',
            'checks' => [
                'journal_integrity',
                'trial_balance',
                'financial_statements',
                'accounts_receivable',
                'accounts_payable',
                'cash_flow_consistency',
                'inventory_close',
                'orphan_journals',
            ],
        ],
        'transaction_processing' => [
            'title_key' => 'accounting_health_group_transaction_processing',
            'description_key' => 'accounting_health_group_transaction_processing_description',
            'details_id' => 'transaction-processing-details',
            'checks' => [
                'failed_jobs',
                'queue_processing',
            ],
        ],
        'accounting_setup' => [
            'title_key' => 'accounting_health_group_accounting_setup',
            'description_key' => 'accounting_health_group_accounting_setup_description',
            'details_id' => 'accounting-setup-details',
            'checks' => [
                'semantic_mappings',
                'payment_accounts',
            ],
        ],
        'historical_integrity' => [
            'title_key' => 'accounting_health_group_historical_integrity',
            'description_key' => 'accounting_health_group_historical_integrity_description',
            'details_id' => 'historical-integrity-details',
            'checks' => [
                'excess_cumulative_refunds', 'over_applied_sale_payments',
                'payment_to_journal_disagreement', 'orphaned_return_refund_journals',
                'historical_sale_deletion_stock_indicators', 'suspicious_exchange_rates',
                'unsafe_complex_sale_deletion_exposure',
                'reward_missing_award', 'reward_duplicate_award', 'reward_balance_disagreement',
                'reward_redemption_integrity', 'reward_payment_cash_misclassification',
                'void_financial_integrity', 'void_imei_integrity',
            ],
        ],
        'tax_accounting' => [
            'title_key' => 'accounting_health_group_sales_tax',
            'description_key' => 'accounting_health_group_sales_tax_description',
            'details_id' => 'tax-accounting-details',
            'checks' => [
                'output_tax_configuration',
                'output_tax_journal_integrity',
                'output_tax_policy_history',
                'purchase_tax_classification_coverage',
            ],
        ],
    ];

    public function build(?array $certification = null, bool $includeAdvanced = true): array
    {
        $queueStats = ['total' => 0, 'failed' => 0, 'pending' => 0, 'posted' => 0];
        $hasCertification = is_array($certification);

        $checks = [
            'journal_integrity' => $this->guardedDetector('journal_integrity', 'accounting_health_check_journal_integrity', 'accounting_health_check_journal_integrity_description', fn () => $this->journalIntegrityCheck()),
            'trial_balance' => $this->guardedDetector('trial_balance', 'accounting_health_check_trial_balance', 'accounting_health_check_trial_balance_description', fn () => $this->trialBalanceCheck()),
            'financial_statements' => $this->notRunCheck(
                'accounting_health_check_financial_statements',
                'accounting_health_check_financial_statements_description',
                'accounting_health_check_financial_statements_not_checked'
            ),
            'semantic_mappings' => $this->guardedDetector('semantic_mappings', 'accounting_health_check_semantic_mappings', 'accounting_health_check_semantic_mappings_description', fn () => $this->semanticMappingCheck()),
            'failed_jobs' => $this->guardedDetector('failed_jobs', 'accounting_health_check_failed_jobs', 'accounting_health_check_failed_jobs_description', function () use (&$queueStats) {
                $queueStats = $this->queueStats();
                return $this->check(
                'accounting_health_check_failed_jobs',
                'accounting_health_check_failed_jobs_description',
                $queueStats['failed'] > 0 ? 'fail' : 'pass',
                $queueStats['failed'] > 0
                    ? 'accounting_health_check_failed_jobs_problem'
                    : 'accounting_health_check_failed_jobs_ok',
                ['count' => $queueStats['failed']],
                'accounting_health_action_view_details',
                '#technical-details',
                'accounting_health_technical_label_failed_jobs',
                $queueStats['failed']);
            }),
            'queue_processing' => $this->guardedDetector('queue_processing', 'accounting_health_check_queue_processing', 'accounting_health_check_queue_processing_description', function () use (&$queueStats) {
                $queueStats = $this->queueStats();
                return $this->check(
                'accounting_health_check_queue_processing',
                'accounting_health_check_queue_processing_description',
                $queueStats['pending'] > 0 ? 'warn' : 'pass',
                $queueStats['pending'] > 0
                    ? 'accounting_health_check_queue_processing_waiting'
                    : 'accounting_health_check_queue_processing_ok',
                ['count' => $queueStats['pending']],
                'accounting_health_action_view_details',
                '#technical-details',
                'accounting_health_technical_label_queue_processing',
                $queueStats['pending']);
            }),
            'accounts_receivable' => $this->notRunCheck(
                'accounting_health_check_customer_balances',
                'accounting_health_check_customer_balances_description',
                'accounting_health_check_customer_balances_not_checked'
            ),
            'accounts_payable' => $this->notRunCheck(
                'accounting_health_check_supplier_balances',
                'accounting_health_check_supplier_balances_description',
                'accounting_health_check_supplier_balances_not_checked'
            ),
            'cash_flow_consistency' => $this->notRunCheck(
                'accounting_health_check_cash_flow_consistency',
                'accounting_health_check_cash_flow_consistency_description',
                'accounting_health_check_cash_flow_consistency_not_checked'
            ),
            'inventory_close' => $this->guardedDetector('inventory_close', 'accounting_health_check_inventory_close', 'accounting_health_check_inventory_close_description', fn () => $this->inventoryCloseCheck()),
            'payment_accounts' => $this->guardedDetector('payment_accounts', 'accounting_health_check_payment_accounts', 'accounting_health_check_payment_accounts_description', fn () => $this->paymentAccountCheck()),
            'orphan_journals' => $this->guardedDetector('orphan_journals', 'accounting_health_check_orphan_journals', 'accounting_health_check_orphan_journals_description', fn () => $this->orphanJournalCheck()),
        ];

        if ($includeAdvanced && config('accounting.health_advanced_enabled', false)) {
            foreach (app(HistoricalIntegrityDiagnosticService::class)->scan() as $key => $finding) {
                $checks[$key] = $this->historicalCheck($finding);
            }
            foreach (app(TaxAccountingDiagnosticService::class)->scan() as $key => $finding) {
                $checks[$key] = $this->historicalCheck($finding);
            }
            foreach (app(AccountingCoreIntegrityDiagnosticService::class)->scan() as $key => $finding) {
                $checks[$key] = $this->historicalCheck($finding);
            }
            foreach (app(RewardPointDiagnosticService::class)->scan() as $key => $finding) {
                $checks[$key] = $this->historicalCheck($finding);
            }
        }

        if ($hasCertification) {
            $checks = $this->applyCertificationResult($checks, $certification);
        }

        $checks = $this->decorateChecks($checks, $hasCertification);
        $groups = $this->groupsFromChecks($checks, $hasCertification);
        $issues = $this->issuesFromChecks($checks, $certification);
        $overall = $this->overallStatus($checks, $issues, $certification);
        $summaryCounts = [
            'integrity' => collect($issues)->whereIn('category', ['action_required', 'manual_review'])->count(),
            'operational' => collect($issues)->where('category', 'operational_review')->count(),
            'setup' => collect($issues)->whereIn('category', ['setup_required', 'safe_repair'])->count(),
            'not_run' => collect($checks)->where('status', 'not_run')->count(),
        ];

        return [
            'overall' => $overall,
            'groups' => $groups,
            'checks' => $checks,
            'issues' => $issues,
            'summary_counts' => $summaryCounts,
            'recent_activity' => $this->recentActivity($certification),
            'queue_stats' => $queueStats,
            'certification' => $certification,
            'generated_at' => now(),
        ];
    }

    public function runCertification(): array
    {
        $missing = $this->missingPrerequisites();
        if ($missing) {
            return [
                'exit_code' => 2,
                'status' => 'fail',
                'checked_at' => now()->toDateTimeString(),
                'output' => __('db.accounting_health_prerequisite_missing', ['extensions' => implode(', ', $missing)]),
                'prerequisites' => ['missing' => $missing],
            ];
        }

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('accounting:certify', [
            '--skip-regressions' => true,
        ], $buffer);

        $output = $buffer->fetch();
        if (trim($output) === '') {
            $output = Artisan::output();
        }
        $output = $this->sanitizeCertificationOutput($output);

        return [
            'exit_code' => $exitCode,
            'status' => $this->certificationStatus($exitCode, $output),
            'checked_at' => now()->toDateTimeString(),
            'output' => $output,
        ];
    }

    private function sanitizeCertificationOutput(string $output): string
    {
        $output = preg_replace('/\x1B(?:[@-_][0-?]*[ -\/]*[@-~]|\][^\x07]*(?:\x07|\x1B\\\\))/', '', $output) ?? $output;
        $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output) ?? $output;

        return trim(str_replace(["\r\n", "\r"], "\n", $output));
    }

    private function queueStats(): array
    {
        return [
            'total' => AccountingSyncQueue::count(),
            'failed' => AccountingSyncQueue::where('status', 'failed')->count(),
            'pending' => AccountingSyncQueue::where('status', 'pending')->count(),
            'posted' => AccountingSyncQueue::where('status', 'posted')->count(),
        ];
    }

    protected function journalIntegrityCheck(): array
    {
        $unbalanced = DB::table('journal_entries as je')
            ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->select('je.id')
            ->groupBy('je.id')
            ->havingRaw('ROUND(COALESCE(SUM(jl.debit), 0), 4) <> ROUND(COALESCE(SUM(jl.credit), 0), 4)')
            ->get()
            ->count();

        $orphanLines = JournalLine::whereDoesntHave('journalEntry')->count();
        $missingAccounts = DB::table('journal_lines as jl')
            ->leftJoin('accounting_accounts as aa', 'aa.id', '=', 'jl.accounting_account_id')
            ->whereNull('aa.id')
            ->count();

        $problemCount = $unbalanced + $orphanLines + $missingAccounts;

        return $this->check(
            'accounting_health_check_journal_integrity',
            'accounting_health_check_journal_integrity_description',
            $problemCount > 0 ? 'fail' : 'pass',
            $problemCount > 0
                ? 'accounting_health_check_journal_integrity_problem'
                : 'accounting_health_check_journal_integrity_ok',
            ['count' => $problemCount],
            'accounting_health_action_view_details',
            '#technical-details',
            'accounting_health_technical_label_journal_integrity',
            $problemCount
        );
    }

    private function trialBalanceCheck(): array
    {
        $totals = DB::table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $difference = round((float) ($totals->debit ?? 0) - (float) ($totals->credit ?? 0), 4);

        return $this->check(
            'accounting_health_check_trial_balance',
            'accounting_health_check_trial_balance_description',
            abs($difference) > 0.0001 ? 'fail' : 'pass',
            abs($difference) > 0.0001
                ? 'accounting_health_check_trial_balance_problem'
                : 'accounting_health_check_trial_balance_ok',
            ['difference' => number_format($difference, 2)],
            'accounting_health_action_view_financial_details',
            route('accounting.trialBalance'),
            'accounting_health_technical_label_trial_balance',
            abs($difference)
        );
    }

    private function inventoryCloseCheck(): array
    {
        try {
            $preview = app(PeriodicInventoryCloseService::class)->preview(now()->startOfMonth()->toDateString(), now()->toDateString());
            $duplicates = DB::table('periodic_inventory_closes')->whereIn('status', ['posted', 'zero'])
                ->groupBy('period_start', 'period_end', 'scope')->havingRaw('COUNT(*) > 1')->count();
            $invalidJournals = DB::table('periodic_inventory_closes as pic')
                ->leftJoin('journal_entries as je', 'je.id', '=', 'pic.journal_entry_id')
                ->where('pic.status', 'posted')->whereNull('je.id')->count();
            $periodAvailable = (bool) ($preview['period_available'] ?? true);
            $actionableBlockingErrors = $preview['blocking_errors']->reject(
                fn (string $error) => !$periodAvailable && $error === 'period_start_after_end'
            );
            $problemCount = $actionableBlockingErrors->count() + $duplicates + $invalidJournals;
            $needsClose = $periodAvailable
                && !$preview['active_close']
                && ($preview['book_inventory'] != 0.0 || $preview['operational_inventory'] != 0.0);
            $status = $problemCount > 0 ? 'fail' : ($needsClose ? 'warn' : 'pass');
            return $this->check(
                'accounting_health_check_inventory_close',
                'accounting_health_check_inventory_close_description',
                $status,
                $problemCount > 0 ? 'accounting_health_check_inventory_close_problem' : ($needsClose ? 'accounting_health_check_inventory_close_needed' : 'accounting_health_check_inventory_close_ok'),
                ['count' => $problemCount],
                'accounting_health_action_inventory_close',
                route('accounting.inventory-close.index'),
                'accounting_health_technical_label_inventory_close',
                $problemCount
            ) + ['blocking_count' => $problemCount, 'needs_close' => $needsClose,
                'resolution_category' => $problemCount > 0 ? 'action_required' : ($needsClose ? 'operational_review' : 'healthy')];
        } catch (Throwable $e) {
            throw $e;
        }
    }

    private function semanticMappingCheck(): array
    {
        $roles = array_merge(AccountingService::CORE_CERTIFICATION_ROLES, AccountingService::FEATURE_CERTIFICATION_ROLES);
        $results = app(AccountingService::class)->validateSemanticRoleMappings($roles, false);
        $failures = array_filter($results, fn ($result) => ($result['status'] ?? null) !== 'pass');

        return $this->check(
            'accounting_health_check_semantic_mappings',
            'accounting_health_check_semantic_mappings_description',
            $failures ? 'fail' : 'pass',
            $failures
                ? 'accounting_health_check_semantic_mappings_problem'
                : 'accounting_health_check_semantic_mappings_ok',
            ['count' => count($failures)],
            'accounting_health_action_configure_accounting',
            route('accounting.semantic-mappings.index'),
            'accounting_health_technical_label_semantic_mappings',
            count($failures)
        );
    }

    private function paymentAccountCheck(): array
    {
        if (app(AccountingModeService::class)->isLegacy()) {
            return $this->check('accounting_health_check_payment_accounts', 'accounting_health_check_payment_accounts_description',
                'pass', 'accounting_health_check_payment_accounts_legacy', [],
                'accounting_health_action_payment_accounts', route('accounts.index'),
                'accounting_health_technical_label_payment_accounts', 0);
        }

        $repair = app(PaymentAccountMappingRepairService::class)->inspect();
        $invalidDefaults = DB::table('users')->whereNotNull('account_id')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('accounts')
                    ->leftJoin('account_mappings', function ($join) {
                        $join->on('account_mappings.mapped_id', '=', 'accounts.id')
                            ->where('account_mappings.mapped_type', '=', \App\Models\Account::class);
                    })
                    ->leftJoin('accounting_accounts', 'accounting_accounts.id', '=', 'account_mappings.accounting_account_id')
                    ->whereColumn('accounts.id', 'users.account_id')
                    ->where('accounts.is_active', true)
                    ->where(function ($invalid) {
                        $invalid->whereNull('account_mappings.id')->orWhereNull('accounting_accounts.id')
                            ->orWhere('accounting_accounts.is_active', false)
                            ->orWhere('accounting_accounts.account_type', '<>', 'asset')
                            ->orWhere('accounting_accounts.is_cash_account', false);
                    });
            })->count();
        $problemCount = $repair['missing_count'] + $repair['invalid_count'] + $invalidDefaults;

        return $this->check('accounting_health_check_payment_accounts', 'accounting_health_check_payment_accounts_description',
            $problemCount ? 'fail' : 'pass',
            $problemCount ? 'accounting_health_check_payment_accounts_problem' : 'accounting_health_check_payment_accounts_ok',
            ['count' => $problemCount],
            $repair['repairable_count'] > 0 ? 'accounting_health_mapping_resolve_mapping' : 'accounting_health_mapping_review_action',
            route('accounting.reconciliation.payment-mappings.index'),
            'accounting_health_technical_label_payment_accounts', $problemCount);
    }

    private function orphanJournalCheck(): array
    {
        $orphanCount = 0;
        $historicalCount = 0;
        $evidence = [];
        $integrity = app(JournalSourceIntegrityService::class);

        $query = JournalEntry::query()
            ->whereNotNull('source_type')
            ->select(['id', 'reference_no', 'entry_date', 'source_type', 'source_id', 'source_subtype', 'event_type',
                'related_journal_entry_id', 'warehouse_id', 'created_by']);
        app(WarehouseAccessService::class)->scope($query, 'warehouse_id');
        $query->chunkById(200, function ($journals) use (&$orphanCount, &$historicalCount, &$evidence, $integrity) {
                foreach ($journals as $journal) {
                    $classification = $integrity->classify($journal);
                    if ($integrity->isFailure($classification)) {
                        $orphanCount++;
                        if (count($evidence) < 10) {
                            $evidence[] = $this->orphanReviewEvidence($journal, $classification);
                        }
                    } elseif ($classification['status'] === JournalSourceIntegrityService::HISTORICAL_ABSENT_REVERSED) {
                        $historicalCount++;
                    }
                }
            });

        // Reversed journals whose legacy source has been intentionally removed
        // remain useful accounting history. Track them for technical context,
        // but do not present a zero-orphan warning to the owner.
        $status = $orphanCount > 0 ? 'fail' : 'pass';

        return $this->check(
            'accounting_health_check_orphan_journals',
            'accounting_health_check_orphan_journals_description',
            $status,
            $orphanCount > 0
                ? 'accounting_health_check_orphan_journals_problem'
                : 'accounting_health_check_orphan_journals_ok',
            ['count' => $orphanCount, 'historical_count' => $historicalCount],
            'accounting_health_action_view_details',
            '#orphan-journal-review',
            'accounting_health_technical_label_orphan_journals',
            $orphanCount
        ) + [
            'review_evidence' => $evidence,
            'review_sample_limit' => 10,
            'review_samples_capped' => $orphanCount > count($evidence),
        ];
    }

    private function orphanReviewEvidence(JournalEntry $journal, array $classification): array
    {
        $totals = DB::table('journal_lines')->where('journal_entry_id', $journal->id)
            ->selectRaw('COALESCE(SUM(debit), 0) debit_total, COALESCE(SUM(credit), 0) credit_total')->first();
        $accounts = DB::table('journal_lines as jl')->join('accounting_accounts as aa', 'aa.id', '=', 'jl.accounting_account_id')
            ->where('jl.journal_entry_id', $journal->id)->orderBy('jl.id')->limit(8)
            ->get(['aa.code', 'aa.name'])->map(fn ($row) => trim($row->code.' '.$row->name))->all();
        $reversal = JournalEntry::query()->where('related_journal_entry_id', $journal->id)
            ->orWhere('id', $journal->related_journal_entry_id ?: 0)->first(['id', 'reference_no']);
        $createdBy = DB::table('users')->where('id', $journal->created_by)->value('name');
        $sourceType = class_basename((string) $journal->source_type);
        $reason = (string) $classification['reason'];
        $sourceState = match ($reason) {
            'soft_deleted_source_without_reversal' => 'soft_deleted_without_reversal',
            'source_row_missing' => 'permanently_missing',
            'missing_source_class' => 'unsupported_source_type',
            default => 'cannot_verify',
        };

        return [
            'journal_id' => (int) $journal->id,
            'journal_reference' => (string) $journal->reference_no,
            'journal_date' => optional($journal->entry_date)->toDateString(),
            'source_type' => $sourceType,
            'source_id' => (int) $journal->source_id,
            'source_state' => $sourceState,
            'source_contract' => $sourceType === 'Payment' ? 'payments.id' : ((string) $journal->source_type).'.id',
            'warehouse_id' => $journal->warehouse_id ? (int) $journal->warehouse_id : null,
            'classification' => (string) $classification['status'],
            'repair_path' => $classification['repair_path'] ?? null,
            'reason' => $reason,
            'debit_total' => round((float) $totals->debit_total, 2),
            'credit_total' => round((float) $totals->credit_total, 2),
            'balanced' => abs((float) $totals->debit_total - (float) $totals->credit_total) < 0.0001,
            'accounts' => $accounts,
            'created_by' => $createdBy,
            'reversal_journal_id' => $reversal?->id,
            'reversal_reference' => $reversal?->reference_no,
        ];
    }

    public function runBoundedWebCheck(): array
    {
        $missing = $this->missingPrerequisites();
        if ($missing) {
            return [
                'exit_code' => 2,
                'status' => 'fail',
                'checked_at' => now()->toDateTimeString(),
                'output' => __('db.accounting_health_prerequisite_missing', ['extensions' => implode(', ', $missing)]),
                'bounded' => true,
                'completed' => false,
                'prerequisites' => ['missing' => $missing],
            ];
        }

        return $this->processRunner->run((int) config('accounting.web_health_timeout_seconds', 8));
    }

    public function buildQuickPayload(): array
    {
        // Quick Health is deliberately independent of every Deep Scan gate.
        $health = $this->build(null, false);
        $statuses = collect($health['checks'])->pluck('status');
        $failedChecks = $statuses->filter(fn ($status) => $status === 'scan_failed')->count();
        $status = $failedChecks > 0 ? 'completed_with_errors'
            : ($statuses->contains('fail') ? 'fail' : ($statuses->contains('warn') ? 'warn' : 'pass'));
        $output = collect($health['checks'])->map(function (array $check, string $key) {
            return str_replace('_', ' ', ucwords($key, '_')).': '.strtoupper((string) $check['status']);
        })->push(__('db.accounting_health_bounded_check_output'))->implode("\n");

        $payload = [
            'exit_code' => $status === 'fail' ? 1 : 0,
            'status' => $status,
            'checked_at' => now()->toDateTimeString(),
            'output' => $output,
            'bounded' => true,
            'completed' => true,
            'prerequisites' => ['missing' => []],
            'summary' => [
                'passed' => $statuses->filter(fn ($value) => $value === 'pass')->count(),
                'failed' => $failedChecks,
                'not_run' => $statuses->filter(fn ($value) => $value === 'not_run')->count(),
            ],
        ];

        // The quick worker has completed a real check. Re-decorate the single
        // in-memory build as completed so live failures are not presented as
        // "Not Checked" while keeping full-certification-only checks explicit.
        $health['checks'] = $this->decorateChecks($health['checks'], true);
        $health['groups'] = $this->groupsFromChecks($health['checks'], true);
        $health['issues'] = $this->issuesFromChecks($health['checks'], $payload);
        $health['overall'] = $this->overallStatus($health['checks'], $health['issues'], $payload);
        $health['certification'] = $payload;

        return $payload + ['health' => $health];
    }

    public function missingPrerequisites(?callable $extensionAvailable = null): array
    {
        $extensionAvailable ??= fn (string $extension): bool => extension_loaded($extension)
            && ($extension !== 'bcmath' || (function_exists('bccomp') && function_exists('bcadd')));

        return array_values(array_filter([
            $extensionAvailable('bcmath') ? null : 'bcmath',
        ]));
    }

    private function orphanJournalRecords(): array
    {
        $integrity = app(JournalSourceIntegrityService::class);
        $records = [];

        JournalEntry::query()
            ->whereNotNull('source_type')
            ->chunkById(200, function ($journals) use (&$records, $integrity) {
                foreach ($journals as $journal) {
                    $classification = $integrity->classify($journal);
                    if (!$integrity->isFailure($classification)) {
                        continue;
                    }

                    $records[] = [
                    'id' => $journal->id,
                    'reference_no' => $journal->reference_no ?: '#' . $journal->id,
                    'entry_date' => optional($journal->entry_date)->toDateString(),
                    'event_type' => $journal->event_type,
                    'source_type' => class_basename((string) $journal->source_type),
                    'source_id' => $journal->source_id,
                    'reason' => $classification['reason'],
                    ];

                    if (count($records) >= 50) {
                        return false;
                    }
                }
            }, 'id');

        return $records;
    }

    private function notRunCheck(string $labelKey, string $descriptionKey, string $detailKey): array
    {
        return $this->check($labelKey, $descriptionKey, 'not_run', $detailKey);
    }

    private function guardedDetector(string $key, string $labelKey, string $descriptionKey, callable $detector): array
    {
        try {
            return $detector();
        } catch (Throwable $exception) {
            $reference = 'HC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
            Log::error('Accounting Quick Health detector failed.', [
                'reference' => $reference,
                'component' => $key,
                'failure_code' => 'detector_error',
                'exception_class' => $exception::class,
            ]);

            return $this->check($labelKey, $descriptionKey, 'scan_failed',
                'accounting_health_detector_failed', ['reference' => $reference],
                'accounting_health_open_failed_checks', '#failed-health-checks',
                $labelKey, 1) + [
                    'failure_reference' => $reference,
                    'failure_code' => 'detector_error',
                    'failure_component' => $key,
                ];
        }
    }

    private function check(
        string $labelKey,
        string $descriptionKey,
        string $status,
        string $detailKey,
        array $detailParams = [],
        ?string $actionLabelKey = null,
        ?string $actionUrl = null,
        ?string $technicalLabelKey = null,
        float|int|null $count = null
    ): array {
        return [
            'label_key' => $labelKey,
            'description_key' => $descriptionKey,
            'status' => $status,
            'detail_key' => $detailKey,
            'detail_params' => $detailParams,
            'action_label_key' => $actionLabelKey,
            'action_url' => $actionUrl,
            'technical_label_key' => $technicalLabelKey,
            'count' => $count,
        ];
    }

    private function historicalCheck(array $finding): array
    {
        $status = match ($finding['status']) {
            'healthy' => 'pass',
            'critical', 'scan_failed' => 'fail',
            'not_applicable' => 'not_run',
            default => 'warn',
        };
        $key = $finding['check_key'];
        $targetKey = str_starts_with($key, 'f012.') ? substr($key, 5) : $key;
        $labelKey = 'accounting_health_check_' . $targetKey . '_title';
        $descriptionKey = 'accounting_health_check_' . $targetKey . '_description';
        $detailKey = 'accounting_health_check_' . $targetKey . '_detail';
        $technicalKey = 'accounting_health_check_' . $targetKey . '_technical';
        return $this->check($labelKey, $descriptionKey, $status, $detailKey, [
            'count' => $finding['affected_count'],
            'amount' => $finding['affected_amount'] ?? '—',
        ], 'accounting_health_action_view_details', '#health-check-'.str_replace('_', '-', $targetKey), $technicalKey, $finding['affected_count']) + [
            'diagnostic' => $finding,
        ];
    }

    private function decorateChecks(array $checks, bool $hasCertification): array
    {
        foreach ($checks as &$check) {
            $displayStatus = $this->displayStatus($check['status'], $hasCertification);
            $check['display_status'] = $displayStatus;
            $check['display_status_key'] = $this->statusTranslationKey($displayStatus);
            $check['tone'] = $this->toneForDisplayStatus($displayStatus);
        }

        return $checks;
    }

    private function applyCertificationResult(array $checks, array $certification): array
    {
        $output = (string) ($certification['output'] ?? '');

        $this->applySummaryStatus($checks['journal_integrity'], $output, 'Journal Integrity', [
            'PASS' => 'accounting_health_certification_journal_integrity_pass',
            'FAIL' => 'accounting_health_certification_journal_integrity_fail',
            'WARN' => 'accounting_health_certification_journal_integrity_warn',
        ]);

        $this->applySummaryStatus($checks['trial_balance'], $output, 'Trial Balance', [
            'PASS' => 'accounting_health_certification_trial_balance_pass',
            'FAIL' => 'accounting_health_certification_trial_balance_fail',
            'WARN' => 'accounting_health_certification_trial_balance_warn',
        ]);

        $this->applySummaryStatus($checks['semantic_mappings'], $output, 'Semantic Account Roles', [
            'PASS' => 'accounting_health_certification_semantic_mappings_pass',
            'FAIL' => 'accounting_health_certification_semantic_mappings_fail',
            'WARN' => 'accounting_health_certification_semantic_mappings_warn',
        ]);

        $checks['financial_statements'] = $this->financialStatementCertificationCheck($checks['financial_statements'], $output);
        $checks['accounts_receivable'] = $this->auditLayerCheck(
            $checks['accounts_receivable'],
            $output,
            'Accounts Receivable',
            'accounting_health_certification_accounts_receivable_pass',
            'accounting_health_certification_accounts_receivable_fail'
        );
        $checks['accounts_payable'] = $this->auditLayerCheck(
            $checks['accounts_payable'],
            $output,
            'Accounts Payable',
            'accounting_health_certification_accounts_payable_pass',
            'accounting_health_certification_accounts_payable_fail'
        );
        $checks['cash_flow_consistency'] = $this->auditLayerCheck(
            $checks['cash_flow_consistency'],
            $output,
            'Cash Flow Reconciliation',
            'accounting_health_certification_cash_flow_pass',
            'accounting_health_certification_cash_flow_fail'
        );
        $checks['orphan_journals'] = $this->auditLayerCheck(
            $checks['orphan_journals'],
            $output,
            'Orphan Journals',
            'accounting_health_certification_orphan_journals_pass',
            'accounting_health_certification_orphan_journals_fail'
        );

        return $checks;
    }

    private function applySummaryStatus(array &$check, string $output, string $summaryLabel, array $detailKeys): void
    {
        $status = $this->summaryStatus($output, $summaryLabel);

        if (!$status) {
            return;
        }

        $check['status'] = strtolower($status) === 'pass'
            ? 'pass'
            : (strtolower($status) === 'warn' ? 'warn' : 'fail');
        $check['detail_key'] = $detailKeys[$status] ?? $check['detail_key'];
        $check['detail_params'] = [];
    }

    private function financialStatementCertificationCheck(array $check, string $output): array
    {
        $summaryLabels = ['Balance Sheet', 'Opening Balance'];
        $statuses = array_filter(array_map(fn ($label) => $this->summaryStatus($output, $label), $summaryLabels));

        if (str_contains($output, '[FAIL CRITICAL] Trial Balance totals do not match Financial Statements')
            || str_contains($output, 'Cash Flow Reconciliation Failed')
            || in_array('FAIL', $statuses, true)) {
            $check['status'] = 'fail';
            $check['detail_key'] = 'accounting_health_certification_financial_statements_fail';
            $check['detail_params'] = [];
            return $check;
        }

        if (str_contains($output, '[PASS] Trial Balance Cross-Verification') || in_array('PASS', $statuses, true)) {
            $check['status'] = 'pass';
            $check['detail_key'] = 'accounting_health_certification_financial_statements_pass';
            $check['detail_params'] = [];
        }

        return $check;
    }

    private function auditLayerCheck(array $check, string $output, string $label, string $passDetailKey, string $failDetailKey): array
    {
        if (str_contains($output, "[FAIL] {$label}")
            || str_contains($output, "[FAIL CRITICAL] {$label}")
            || str_contains($output, "{$label} Failed")) {
            $check['status'] = 'fail';
            $check['detail_key'] = $failDetailKey;
            $check['detail_params'] = [];
            return $check;
        }

        if (str_contains($output, "[PASS] {$label}")) {
            $check['status'] = 'pass';
            $check['detail_key'] = $passDetailKey;
            $check['detail_params'] = [];
        }

        return $check;
    }

    private function summaryStatus(string $output, string $label): ?string
    {
        $pattern = '/^' . preg_quote($label, '/') . '\s+(PASS|WARN|FAIL)\s*$/m';

        if (preg_match($pattern, $output, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function certificationStatus(int $exitCode, string $output): string
    {
        if ($exitCode !== 0) {
            return 'fail';
        }

        return str_contains($output, 'READY WITH WARNINGS') ? 'warn' : 'pass';
    }

    private function groupsFromChecks(array $checks, bool $hasCertification): array
    {
        $groups = [];

        foreach (self::GROUPS as $key => $definition) {
            $groupChecks = array_intersect_key($checks, array_flip($definition['checks']));
            $rawStatus = $groupChecks === [] ? 'not_run' : $this->aggregateRawStatus($groupChecks, $hasCertification);
            $displayStatus = $this->displayStatus($rawStatus, $hasCertification);

            $groups[$key] = [
                'key' => $key,
                'title_key' => $definition['title_key'],
                'description_key' => $definition['description_key'],
                'details_id' => $definition['details_id'],
                'checks' => $groupChecks,
                'status' => $rawStatus,
                'display_status' => $displayStatus,
                'display_status_key' => $this->statusTranslationKey($displayStatus),
                'tone' => $this->toneForDisplayStatus($displayStatus),
                'summary_key' => $groupChecks === [] && in_array($key, ['historical_integrity', 'tax_accounting'], true)
                    ? 'accounting_health_details_advanced_disabled'
                    : $this->groupSummaryKey($key, $displayStatus),
                'action_label_key' => $this->groupActionLabelKey($key, $displayStatus),
                'action_url' => $this->groupActionUrl($key, $displayStatus),
            ];
        }

        return $groups;
    }

    private function aggregateRawStatus(array $checks, bool $hasCertification): string
    {
        if (!$hasCertification && collect($checks)->contains(fn ($check) => $check['status'] === 'not_run')) {
            $hasLiveConcern = collect($checks)->contains(fn ($check) => in_array($check['status'], ['fail', 'warn', 'scan_failed'], true));

            return $hasLiveConcern ? 'warn' : 'not_run';
        }

        if (collect($checks)->contains(fn ($check) => in_array($check['status'], ['fail', 'scan_failed'], true))) {
            return 'fail';
        }

        if (collect($checks)->contains(fn ($check) => $check['status'] === 'warn')) {
            return 'warn';
        }

        if (collect($checks)->contains(fn ($check) => $check['status'] === 'not_run')) {
            return 'not_run';
        }

        return 'pass';
    }

    private function overallStatus(array $checks, array $issues, ?array $certification): array
    {
        if (!$certification) {
            return [
                'level' => 'neutral',
                'display_status' => 'not_checked',
                'label_key' => 'accounting_health_status_not_checked',
                'message_key' => 'accounting_health_overall_not_checked_message',
                'issues_count' => count($issues),
            ];
        }

        // The customer-facing status reflects only issues the user can resolve.
        // Internal certification failures remain in logs for support/development.
        $hasFail = collect($checks)->contains(fn ($check) => in_array($check['status'], ['fail', 'scan_failed'], true));
        $hasWarn = collect($checks)->contains(fn ($check) => $check['status'] === 'warn');

        if ($hasFail) {
            return [
                'level' => 'red',
                'display_status' => 'action_required',
                'label_key' => 'accounting_health_status_action_required',
                'message_key' => 'accounting_health_overall_action_required_message',
                'issues_count' => count($issues),
            ];
        }

        if ($hasWarn) {
            return [
                'level' => 'yellow',
                'display_status' => 'needs_review',
                'label_key' => 'accounting_health_status_needs_review',
                'message_key' => 'accounting_health_overall_needs_review_message',
                'issues_count' => count($issues),
            ];
        }

        return [
            'level' => 'green',
            'display_status' => 'healthy',
            'label_key' => 'accounting_health_status_healthy',
            'message_key' => 'accounting_health_overall_healthy_message',
            'issues_count' => count($issues),
        ];
    }

    private function issuesFromChecks(array $checks, ?array $certification): array
    {
        $issues = [];
        $hasCertification = is_array($certification);

        foreach ($checks as $key => $check) {
            if (!in_array($check['status'], ['fail', 'warn', 'scan_failed'], true)) {
                continue;
            }

            $issues[$key] = $this->ownerFriendlyIssue($key, $check, $hasCertification);
        }

        if (($certification['status'] ?? null) === 'fail' && empty($issues)) {
            $issues['certification'] = [
                'key' => 'certification',
                'severity' => 'fail',
                'tone' => 'fail',
                'title_key' => 'accounting_health_issue_certification_failed_title',
                'explanation_key' => 'accounting_health_issue_certification_failed_explanation',
                'explanation_params' => [],
                'action_label_key' => 'accounting_health_action_view_details',
                'action_url' => '#technical-details',
                'technical_label_key' => 'accounting_health_technical_label_certification',
            ];
        }

        return array_values($issues);
    }

    private function ownerFriendlyIssue(string $key, array $check, bool $hasCertification): array
    {
        $severity = $hasCertification && in_array($check['status'], ['fail', 'scan_failed'], true) ? 'fail' : 'warn';

        if ($check['status'] === 'scan_failed') {
            return [
                'key' => $key,
                'severity' => 'fail',
                'category' => 'action_required',
                'tone' => 'fail',
                'title_key' => $check['label_key'],
                'explanation_key' => 'accounting_health_detector_failed',
                'explanation_params' => ['reference' => $check['failure_reference']],
                'action_label_key' => 'accounting_health_open_failed_checks',
                'action_url' => '#failed-health-checks',
                'technical_label_key' => $check['technical_label_key'],
            ];
        }

        $issue = [
            'key' => $key,
            'severity' => $severity,
            'category' => $severity === 'fail' ? 'action_required' : 'manual_review',
            'tone' => $this->toneForDisplayStatus($this->displayStatus($check['status'], $hasCertification)),
            'title_key' => 'accounting_health_issue_generic_title',
            'explanation_key' => 'accounting_health_issue_generic_explanation',
            'explanation_params' => [],
            'action_label_key' => $check['action_label_key'] ?: 'accounting_health_action_review_issue',
            'action_url' => $check['action_url'],
            'technical_label_key' => $check['technical_label_key'],
        ];

        if (isset($check['diagnostic'])) {
            return array_merge($issue, [
                'title_key' => $check['label_key'],
                'explanation_key' => $check['detail_key'],
                'explanation_params' => $check['detail_params'],
                'action_label_key' => 'accounting_health_action_view_details',
                'action_url' => $check['action_url'],
            ]);
        }

        if ($key === 'semantic_mappings') {
            return array_merge($issue, [
                'category' => 'setup_required',
                'title_key' => 'accounting_health_issue_setup_incomplete_title',
                'explanation_key' => 'accounting_health_issue_setup_incomplete_explanation',
                'explanation_params' => ['count' => $check['count'] ?? 0],
                'action_label_key' => 'accounting_health_action_configure_accounting',
            ]);
        }

        if ($key === 'inventory_close') {
            return array_merge($issue, [
                'category' => ($check['blocking_count'] ?? 0) > 0 ? 'action_required' : 'operational_review',
                'severity' => ($check['blocking_count'] ?? 0) > 0 ? 'fail' : 'operational_review',
                'title_key' => $check['label_key'],
                'explanation_key' => $check['detail_key'],
                'explanation_params' => $check['detail_params'] ?? [],
                'action_label_key' => 'accounting_health_action_inventory_close',
            ]);
        }

        if ($key === 'orphan_journals') {
            return array_merge($issue, [
                'category' => 'manual_review',
                'severity' => 'manual_review',
                'title_key' => ($check['count'] ?? 0) === 1
                    ? 'accounting_health_issue_orphan_journal_one_title'
                    : 'accounting_health_issue_orphan_journal_many_title',
                'explanation_key' => 'accounting_health_issue_orphan_journal_explanation',
                'explanation_params' => ['count' => $check['count'] ?? 0],
                'action_label_key' => 'accounting_health_action_view_details',
            ]);
        }

        if ($key === 'payment_accounts') {
            return array_merge($issue, [
                'category' => str_contains((string) ($check['action_url'] ?? ''), 'payment-mappings') ? 'safe_repair' : 'setup_required',
                'severity' => 'setup_required',
                'title_key' => $check['label_key'],
                'explanation_key' => $check['detail_key'],
                'explanation_params' => $check['detail_params'] ?? [],
            ]);
        }

        if ($key === 'failed_jobs') {
            return array_merge($issue, [
                'title_key' => ($check['count'] ?? 0) === 1
                    ? 'accounting_health_issue_failed_job_one_title'
                    : 'accounting_health_issue_failed_job_many_title',
                'explanation_key' => 'accounting_health_issue_failed_job_explanation',
                'explanation_params' => ['count' => $check['count'] ?? 0],
                'action_label_key' => 'accounting_health_action_view_details',
            ]);
        }

        if ($key === 'queue_processing') {
            return array_merge($issue, [
                'title_key' => 'accounting_health_issue_queue_waiting_title',
                'explanation_key' => 'accounting_health_issue_queue_waiting_explanation',
                'explanation_params' => ['count' => $check['count'] ?? 0],
                'action_label_key' => 'accounting_health_action_view_details',
            ]);
        }

        if ($key === 'trial_balance') {
            return array_merge($issue, [
                'title_key' => 'accounting_health_issue_trial_balance_title',
                'explanation_key' => 'accounting_health_issue_trial_balance_explanation',
                'explanation_params' => $check['detail_params'] ?? [],
                'action_label_key' => 'accounting_health_action_view_financial_details',
            ]);
        }

        if (in_array($key, ['financial_statements', 'accounts_receivable', 'accounts_payable', 'cash_flow_consistency'], true)) {
            return array_merge($issue, [
                'title_key' => $check['label_key'],
                'explanation_key' => $check['detail_key'],
                'explanation_params' => $check['detail_params'] ?? [],
                'action_label_key' => 'accounting_health_action_view_financial_details',
                'action_url' => '#financial-records-details',
            ]);
        }

        return $issue;
    }

    private function recentActivity(?array $certification): array
    {
        $activity = [];

        if ($certification) {
            $activity[] = [
                'label_key' => 'accounting_health_activity_health_check_completed',
                'detail_key' => match ($certification['status'] ?? null) {
                    'fail' => 'accounting_health_activity_health_check_failed_detail',
                    'warn' => 'accounting_health_activity_health_check_warning_detail',
                    default => 'accounting_health_activity_health_check_success_detail',
                },
                'detail_params' => ['code' => $certification['exit_code']],
                'time' => $certification['checked_at'],
            ];
        }

        AccountingSyncQueue::query()
            ->latest('updated_at')
            ->latest('id')
            ->limit(4)
            ->get()
            ->each(function (AccountingSyncQueue $queue) use (&$activity) {
                $activity[] = [
                    'label_key' => 'accounting_health_activity_transaction_job',
                    'label_params' => ['source' => class_basename($queue->source_type)],
                    'detail_key' => match ($queue->status) {
                        'posted' => 'accounting_health_activity_job_posted',
                        'failed' => 'accounting_health_activity_job_failed',
                        'reversed' => 'accounting_health_activity_job_reversed',
                        default => 'accounting_health_activity_job_pending',
                    },
                    'detail_params' => [],
                    'time' => optional($queue->updated_at)->toDateTimeString(),
                ];
            });

        JournalEntry::query()
            ->latest('created_at')
            ->latest('id')
            ->limit(3)
            ->get()
            ->each(function (JournalEntry $entry) use (&$activity) {
                $activity[] = [
                    'label_key' => $this->eventLabelKey((string) $entry->event_type),
                    'detail_key' => 'accounting_health_activity_reference_detail',
                    'detail_params' => [
                        'reference' => $entry->reference_no ?: '#' . $entry->id,
                    ],
                    'time' => optional($entry->created_at)->toDateTimeString(),
                ];
            });

        return array_slice($activity, 0, 6);
    }

    private function eventLabelKey(string $eventType): string
    {
        $eventType = strtolower($eventType);

        if (str_contains($eventType, 'money_transfer')) {
            return 'accounting_health_activity_money_transfer_recorded';
        }

        if (str_contains($eventType, 'opening')) {
            return 'accounting_health_activity_opening_balance_created';
        }

        if (str_contains($eventType, 'sale_return')) {
            return 'accounting_health_activity_sale_return_recorded';
        }

        if (str_contains($eventType, 'purchase_return')) {
            return 'accounting_health_activity_purchase_return_recorded';
        }

        if (str_contains($eventType, 'sale')) {
            return 'accounting_health_activity_sale_recorded';
        }

        if (str_contains($eventType, 'purchase')) {
            return 'accounting_health_activity_purchase_recorded';
        }

        if (str_contains($eventType, 'payment')) {
            return 'accounting_health_activity_payment_recorded';
        }

        if (str_contains($eventType, 'expense')) {
            return 'accounting_health_activity_expense_recorded';
        }

        if (str_contains($eventType, 'income')) {
            return 'accounting_health_activity_income_recorded';
        }

        return 'accounting_health_activity_entry_recorded';
    }

    private function displayStatus(string $status, bool $hasCertification): string
    {
        if ($status === 'pass') {
            return 'healthy';
        }

        if ($status === 'not_run') {
            return 'not_checked';
        }

        if (in_array($status, ['fail', 'scan_failed'], true)) {
            return $hasCertification ? 'action_required' : 'needs_review';
        }

        return 'needs_review';
    }

    private function statusTranslationKey(string $displayStatus): string
    {
        return match ($displayStatus) {
            'healthy' => 'accounting_health_status_healthy',
            'action_required' => 'accounting_health_status_action_required',
            'not_checked' => 'accounting_health_status_not_checked',
            default => 'accounting_health_status_needs_review',
        };
    }

    private function toneForDisplayStatus(string $displayStatus): string
    {
        return match ($displayStatus) {
            'healthy' => 'pass',
            'action_required' => 'fail',
            'not_checked' => 'not_run',
            default => 'warn',
        };
    }

    private function groupSummaryKey(string $group, string $displayStatus): string
    {
        if ($group === 'historical_integrity') return "accounting_health_group_historical_integrity_{$displayStatus}";
        if ($group === 'tax_accounting') return "accounting_health_group_sales_tax_{$displayStatus}";
        return "accounting_health_group_{$group}_{$displayStatus}";
    }

    private function groupActionLabelKey(string $group, string $displayStatus): ?string
    {
        if ($displayStatus === 'healthy') {
            return null;
        }

        return match ($group) {
            'accounting_setup' => 'accounting_health_action_configure_accounting',
            'financial_records' => 'accounting_health_action_view_financial_details',
            default => 'accounting_health_action_view_details',
        };
    }

    private function groupActionUrl(string $group, string $displayStatus): ?string
    {
        if ($displayStatus === 'healthy') {
            return null;
        }

        return match ($group) {
            'accounting_setup' => route('accounting.semantic-mappings.index'),
            default => '#' . (self::GROUPS[$group]['details_id'] ?? 'technical-details'),
        };
    }
}
