<?php

namespace App\Services\Accounting;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\GiftCard;
use App\Models\GiftCardRecharge;
use App\Models\Income;
use App\Models\MoneyTransfer;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\Purchase;
use App\Models\ReturnPurchase;
use App\Models\Returns;
use App\Models\Sale;
use App\Models\Supplier;

/** Single, declarative source of truth for accounting diagnostic event families. */
class AccountingEventFamilyRegistry
{
    public const VERSION = 1;

    public function all(): array
    {
        return [
            'sales' => $this->family(Sale::class, 'sales', ['sale_created', 'sale_updated'], true),
            'sale_payments' => $this->family(Payment::class, 'payments', ['sale_payment_created'], true, 'sale'),
            'purchases' => $this->family(Purchase::class, 'purchases', ['purchase_created', 'purchase_updated'], true),
            'purchase_payments' => $this->family(Payment::class, 'payments', ['purchase_payment_created'], true, 'purchase'),
            'sale_returns' => $this->family(Returns::class, 'returns', ['sale_return_created', 'sale_return_updated'], true),
            'sale_return_refunds' => $this->family(Payment::class, 'payments', ['sale_refund_created'], true, 'sale_refund'),
            'purchase_returns' => $this->family(ReturnPurchase::class, 'return_purchases', ['purchase_return_created', 'purchase_return_updated'], true),
            'purchase_return_refunds' => $this->family(Payment::class, 'payments', ['purchase_refund_created'], true, 'purchase_refund'),
            'customer_deposits' => $this->family(Deposit::class, 'deposits', ['customer_deposit', 'customer_opening_deposit_created'], false),
            'expenses' => $this->family(Expense::class, 'expenses', ['expense_created', 'expense_updated'], true),
            'incomes' => $this->family(Income::class, 'incomes', ['income_created', 'income_updated'], true),
            'payroll' => $this->family(Payroll::class, 'payrolls', ['payroll_paid', 'payroll_updated'], true),
            'money_transfers' => $this->family(MoneyTransfer::class, 'money_transfers', ['money_transfer'], false),
            'customer_opening_balances' => $this->family(Customer::class, 'customers', ['customer_opening_balance_created'], false),
            'supplier_opening_balances' => $this->family(Supplier::class, 'suppliers', ['supplier_opening_balance_created'], false),
            'periodic_inventory_close' => $this->virtual('journal_entries', 'activation', ['opening_balance', 'periodic_inventory_close']),
            'gift_cards' => $this->virtual('gift_cards', GiftCard::class, ['gift_card_created', 'gift_card_updated']),
            'gift_card_recharges' => $this->virtual('gift_card_recharges', GiftCardRecharge::class, ['gift_card_recharged']),
            'deposit_consumption' => $this->virtual('payments', Payment::class, ['deposit_consumed', 'deposit_restored']),
            'repair' => $this->module('Repair', Sale::class, 'sales', ['repair_invoice_created', 'repair_invoice_updated']),
            'ecommerce' => $this->module('Ecommerce', Sale::class, 'sales', ['ecommerce_order_created', 'ecommerce_payment_confirmed']),
            'social_commerce' => $this->module('SocialCommerce', Sale::class, 'sales', ['social_commerce_order_created']),
        ];
    }

    public function enabled(): array
    {
        return array_filter($this->all(), fn (array $family) => $family['availability'] === 'enabled');
    }

    private function family(string $model, string $table, array $events, bool $warehouse, ?string $variant = null): array
    {
        return ['source_type' => $model, 'table' => $table, 'primary_key' => 'id', 'event_types' => $events,
            'posting_eligibility' => 'accounting_status_and_cutover', 'amount_strategy' => 'source_preserved_currency',
            'expected_journal_family' => $events[0], 'reversal_behavior' => 'related_journal_entry', 'queue_support' => true,
            'warehouse_attribution' => $warehouse ? 'source_or_parent' : 'global', 'cutover_behavior' => 'exclude_pre_cutover',
            'closed_period_behavior' => 'blocked_by_accounting_service', 'module' => null, 'diagnostic_handler' => 'registry_event_consistency',
            'label' => str_replace('_', ' ', $variant ?: $table), 'repair_supported' => false, 'availability' => class_exists($model) ? 'enabled' : 'not_applicable'];
    }

    private function virtual(string $table, string $sourceType, array $events): array
    {
        $family = $this->family($sourceType, $table, $events, false);
        $family['queue_support'] = false;
        $family['posting_eligibility'] = 'event_defined';
        $family['availability'] = \Illuminate\Support\Facades\Schema::hasTable($table) ? 'enabled' : 'not_applicable';
        return $family;
    }

    private function module(string $module, string $model, string $table, array $events): array
    {
        $family = $this->family($model, $table, $events, true);
        $family['module'] = $module;
        $family['availability'] = is_dir(base_path('Modules/'.$module)) ? 'enabled' : 'not_applicable';
        return $family;
    }
}
