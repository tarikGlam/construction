<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AccountingActivationService;
use App\Services\AccountingService;
use App\Models\AccountingConfig;
use App\Models\JournalEntry;

class AccountingActivationController extends Controller
{
    protected $activationService;

    public function __construct(AccountingActivationService $activationService)
    {
        $this->activationService = $activationService;
    }

    public function index(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('accounting-activation-manage'), 403);
        $config = AccountingConfig::firstOrCreate(['id' => 1]);

        if (app(\App\Services\AccountingModeService::class)->isDoubleEntryAuthoritative()) {
            return redirect()->route('accounting.trialBalance')->with('message', 'Accounting is already activated.');
        }

        $requiresExistingMode = $this->activationService->requiresExistingBusinessMode();
        abort_unless($requiresExistingMode, 403, 'New installations are initialized automatically.');
        $preflight = $this->activationService->preflight();
        $validationResults = collect($preflight['blocking'])->map(fn ($message) => ['label' => 'Accounting cutover', 'status' => 'fail', 'message' => $message])
            ->merge(collect($preflight['warnings'])->map(fn ($message) => ['label' => 'Accounting cutover', 'status' => 'warn', 'message' => $message]))->values()->all();
        $balances = $preflight['balances'];
        $mode = 'existing_business';
        $canActivate = $preflight['status'] !== 'blocking';
        $overallStatus = $preflight['status'] === 'blocking' ? 'fail' : ($preflight['status'] === 'warning' ? 'warn' : 'pass');

        return view('backend.accounting.activation.index', [
            'mode' => $mode,
            'hasHistoricalData' => $requiresExistingMode,
            'validationResults' => $validationResults,
            'summary' => $balances,
            'assets' => [
                'cash_and_bank' => $balances['cash_and_bank'],
                'accounts_receivable' => $balances['accounts_receivable'],
                'inventory_value' => $balances['inventory_value'],
                'total' => $balances['total_assets'],
            ],
            'liabilities' => [
                'accounts_payable' => $balances['accounts_payable'],
                'total' => $balances['total_liabilities'],
            ],
            'equity' => $balances['opening_balance_equity'],
            'openingJournalPreview' => $this->buildOpeningJournalPreview($balances, $mode),
            'canActivate' => $canActivate,
            'overallStatus' => $overallStatus,
            'activationDate' => now()->toDateString(),
        ]);
    }

    public function activate(Request $request)
    {
        abort_unless($request->user() && $request->user()->can('accounting-activation-manage'), 403);
        $config = AccountingConfig::firstOrCreate(['id' => 1]);
        if (app(\App\Services\AccountingModeService::class)->isDoubleEntryAuthoritative()) {
            return redirect()->route('accounting.trialBalance')->with('message', 'Accounting is already activated.');
        }

        $hasHistoricalData = $this->activationService->requiresExistingBusinessMode();
        abort_unless($hasHistoricalData, 403, 'New installations are initialized automatically.');
        $mode = 'existing_business';

        $openingBalancesReviewed = false;
        if ($mode === 'existing_business') {
            $request->validate([
                'opening_balances_confirmed' => 'accepted',
                'backup_confirmed' => 'accepted',
            ]);
            $openingBalancesReviewed = true;
        }

        try {
            $session = $this->activationService->activate($mode, $openingBalancesReviewed, true);
            $journal = $session->opening_journal_entry_id
                ? JournalEntry::find($session->opening_journal_entry_id)
                : null;

            return view('backend.accounting.activation.complete', [
                'session' => $session,
                'journal' => $journal,
                'activatedBy' => auth()->user()->name ?? 'System',
            ]);
        } catch (\Exception $e) {
            \Log::error('Accounting activation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->with('not_permitted', __('db.We could not complete this action. Please try again.'));
        }
    }

    private function normalizeChecklist(array $checklist): array
    {
        $labels = [
            'Operational data analyzed',
            'Product stock readable',
        ];

        return collect($checklist)->values()->map(function ($item, $index) use ($labels) {
            $status = strtolower($item['status'] ?? ($item ? 'pass' : 'fail'));

            return [
                'label' => $item['label'] ?? ($labels[$index] ?? 'Readiness validation'),
                'status' => in_array($status, ['pass', 'warn', 'fail'], true) ? $status : 'warn',
                'message' => $item['message'] ?? null,
            ];
        })->all();
    }

    private function buildOpeningJournalPreview(array $balances, string $mode): array
    {
        if ($mode === 'new_business') {
            return ['lines' => [], 'debit_total' => 0, 'credit_total' => 0, 'difference' => 0];
        }

        $lines = [];
        $addLine = function ($account, $debit, $credit) use (&$lines) {
            if ((float) $debit !== 0.0 || (float) $credit !== 0.0) {
                $lines[] = compact('account', 'debit', 'credit');
            }
        };

        $accountLabel = function (string $role, string $fallback) {
            try {
                $account = app(AccountingService::class)->getRoleAccount($role);
                return trim($account->code . ' - ' . $account->name);
            } catch (\Throwable $e) {
                return $fallback;
            }
        };

        $addLine($accountLabel(AccountingService::ROLE_ACCOUNTS_RECEIVABLE, 'Accounts Receivable'), max(0, $balances['accounts_receivable']), 0);
        $addLine($accountLabel(AccountingService::ROLE_INVENTORY, 'Inventory'), max(0, $balances['inventory_value']), 0);
        $addLine($accountLabel(AccountingService::ROLE_CASH, 'Cash & Bank'), max(0, $balances['cash_and_bank']), max(0, -$balances['cash_and_bank']));
        $addLine($accountLabel(AccountingService::ROLE_ACCOUNTS_PAYABLE, 'Accounts Payable'), 0, max(0, $balances['accounts_payable']));
        $addLine(
            $accountLabel(AccountingService::ROLE_OPENING_EQUITY, 'Opening Balance Equity'),
            max(0, -$balances['opening_balance_equity']),
            max(0, $balances['opening_balance_equity'])
        );

        $debitTotal = collect($lines)->sum('debit');
        $creditTotal = collect($lines)->sum('credit');

        return [
            'lines' => $lines,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'difference' => $debitTotal - $creditTotal,
        ];
    }
}
