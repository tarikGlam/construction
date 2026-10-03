<?php

namespace App\Services\Billing;

use App\Models\AccountingConfig;
use App\Models\landlord\TenantBillingProfile;
use App\Services\AccountingModeService;
use App\Services\FinancialReportingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommissionProfitService
{
    // This service must run inside the tenant database context.
    public function snapshot(TenantBillingProfile $profile, string $start, string $end): array
    {
        if (!config('database.connections.saleprosaas_landlord')) {
            throw new RuntimeException('Subscription profit calculations are only available in SaaS mode.');
        }
        if (!app(AccountingModeService::class)->isDoubleEntryAuthoritative()) {
            throw new RuntimeException('Activate and reconcile double-entry accounting before billing.');
        }
        $cutover = AccountingConfig::find(1);
        if (substr((string) $cutover->start_date, 0, 10) > $start) {
            throw new RuntimeException('Accounting does not cover the complete billing period.');
        }
        $setting = DB::table('general_settings')->latest('id')->first();
        $currency = DB::table('currencies')->where('id', $setting->currency)->value('code');
        if (strtoupper((string) $currency) !== $profile->currency) {
            throw new RuntimeException('Tenant and billing currencies differ. Currency conversion is not supported.');
        }
        if (DB::table('accounting_sync_queue')->whereIn('status', ['pending', 'failed'])->exists()) {
            throw new RuntimeException('Resolve pending or failed accounting postings before billing.');
        }
        $pnl = app(FinancialReportingService::class)->getProfitAndLoss($start, $end);
        if ($pnl['inventory_close_required']) {
            throw new RuntimeException('Post the company inventory close for this exact billing period first.');
        }
        $account = DB::table('accounting_accounts')->find($profile->saas_expense_account_id);
        if (!$account || $account->account_type !== 'expense' || $account->code !== 'SAAS-COMMISSION') {
            throw new RuntimeException('The dedicated SaaS commission expense account is missing or changed.');
        }
        $addback = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('accounting_account_id', $account->id)
            ->whereDate('entry_date', '>=', $start)->whereDate('entry_date', '<=', $end)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as amount')->value('amount');
        $net = CommissionMoney::amount($pnl['net_profit']);
        $addback = CommissionMoney::amount($addback);
        $profit = CommissionMoney::profit($net, $addback);
        return [
            'net_profit' => $net, 'fee_addback' => $addback, 'billable_profit' => $profit,
            'amount' => CommissionMoney::percentage($profit, $profile->commission_rate),
            'calculation' => [
                'version' => 'profit-commission-v1', 'period_start' => $start, 'period_end' => $end,
                'currency' => $profile->currency, 'timezone' => $profile->timezone,
                'commission_rate' => $profile->commission_rate, 'pnl' => $pnl,
                'saas_expense_account_id' => $account->id, 'fee_addback' => $addback,
            ],
        ];
    }
}
