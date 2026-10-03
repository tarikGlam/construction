<?php

namespace App\Services;

use App\Models\AccountingConfig;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class SupplierOpeningBalanceAuditService
{
    /** Read-only inventory of opening payable inconsistencies. */
    public function audit(): array
    {
        $accountingEnabled = (bool) AccountingConfig::query()->whereKey(1)->value('enabled');
        $apAccountId = DB::table('account_mappings')
            ->where('mapped_type', AccountingService::ROLE_ACCOUNTS_PAYABLE)
            ->where('mapped_id', 0)->value('accounting_account_id');
        $rows = [];

        foreach (DB::table('suppliers')->orderBy('id')->get() as $supplier) {
            $purchases = DB::table('purchases')->where('supplier_id', $supplier->id)
                ->whereNull('deleted_at')->whereRaw('LOWER(purchase_type) = ?', ['opening balance'])->get();
            $amounts = $purchases->pluck('grand_total')->map(fn ($value) => (float) $value)->all();
            $purchaseAmount = array_sum($amounts);
            $purchaseIds = $purchases->pluck('id')->all();
            $paid = $purchaseIds ? (float) DB::table('payments')->whereIn('purchase_id', $purchaseIds)
                ->whereNull('return_id')->whereNull('purchase_return_id')->sum('amount') : 0.0;
            $journals = DB::table('journal_entries')
                ->whereIn('source_type', [Supplier::class, class_basename(Supplier::class), '\\' . Supplier::class])
                ->where('source_id', $supplier->id)->get();
            $journalIds = $journals->pluck('id')->all();
            $apNet = $apAccountId && $journalIds
                ? DB::table('journal_lines')->whereIn('journal_entry_id', $journalIds)
                    ->where('accounting_account_id', $apAccountId)
                    ->selectRaw('COALESCE(SUM(credit - debit), 0) as net')->value('net')
                : null;
            $journalState = $accountingEnabled
                ? ($apAccountId ? 'entries=' . $journals->count() . '; net_ap=' . ($apNet ?? 'unknown') : 'AP mapping missing')
                : 'accounting disabled';
            $conditions = [];
            $opening = (float) $supplier->opening_balance;

            if ($opening > 0 && $purchases->isEmpty()) {
                $conditions[] = ['missing_opening_purchase', 'review_source_history'];
            }
            if ($purchases->count() > 1) {
                $conditions[] = ['multiple_opening_purchases', 'manual_payable_reconciliation'];
            }
            if (abs($opening - $purchaseAmount) > 0.000001) {
                $conditions[] = ['opening_balance_mismatch', 'manual_payable_reconciliation'];
            }
            if ($paid - $purchaseAmount > 0.000001) {
                $conditions[] = ['payments_exceed_opening_payable', 'review_payments_and_credits'];
            }
            foreach ($purchases as $purchase) {
                if (!DB::table('warehouses')->where('id', $purchase->warehouse_id)->where('is_active', true)->exists()) {
                    $conditions[] = ['invalid_opening_warehouse', 'review_warehouse_assignment'];
                }
                if (!$purchase->currency_id || !$purchase->exchange_rate || (float) $purchase->exchange_rate <= 0) {
                    $conditions[] = ['missing_currency_metadata', 'review_transaction_currency'];
                }
                if (abs((float) $purchase->paid_amount - (float) DB::table('payments')
                    ->where('purchase_id', $purchase->id)->whereNull('return_id')
                    ->whereNull('purchase_return_id')->sum('amount')) > 0.000001) {
                    $conditions[] = ['paid_amount_mismatch', 'review_payment_lifecycle'];
                }
            }
            if ($accountingEnabled && $opening > 0 && $journals->isEmpty()) {
                $conditions[] = ['missing_opening_journal', 'review_accounting_activation'];
            }
            if ($accountingEnabled && $apAccountId && $apNet !== null && abs((float) $apNet - $opening) > 0.000001) {
                $conditions[] = ['opening_journal_amount_mismatch', 'manual_journal_reconciliation'];
            }
            $duplicateEvents = $journals->groupBy('event_type')->filter(fn ($group) => $group->count() > 1);
            if ($duplicateEvents->isNotEmpty()) {
                $conditions[] = ['duplicate_opening_journal_event', 'manual_journal_reconciliation'];
            }

            foreach ($conditions as [$condition, $remediation]) {
                $rows[] = [
                    'supplier_id' => (int) $supplier->id,
                    'supplier_name' => $supplier->name,
                    'condition' => $condition,
                    'supplier_opening_balance' => $opening,
                    'opening_purchase_amounts' => $amounts,
                    'paid_amount' => $paid,
                    'remaining_payable' => max(0, $purchaseAmount - $paid),
                    'journal_state' => $journalState,
                    'remediation' => $remediation,
                ];
            }
        }
        return $rows;
    }
}
