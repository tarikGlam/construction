<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Models\ReturnPurchase;
use App\Models\Returns;
use App\Models\Sale;
use App\Models\SaleExchange;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TaxAccountingComponentService
{
    public const POLICY_V2 = 'tax_split_v2';
    public const POLICY_LEGACY = 'legacy_gross';

    public function sale(Sale $sale): array
    {
        $fiscal = app(ZatcaIntegrationService::class)->saleAccountingComponents($sale);
        if ($fiscal !== null) { return $fiscal; }
        $lineTax = $this->sum('product_sales', 'sale_id', $sale->id, 'tax');
        $headerTax = $this->money($sale->total_tax ?? 0);
        if (bccomp($lineTax, $headerTax, 4) !== 0) throw new RuntimeException('Sale tax snapshot does not reconcile to its persisted product lines.');
        $outputTax = bcadd($headerTax, $this->money($sale->order_tax ?? 0), 4);
        $grand = $this->money($sale->grand_total);
        if (bccomp($outputTax, '0.0000', 4) < 0 || bccomp($outputTax, $grand, 4) > 0)
            throw new RuntimeException('Sale tax snapshot cannot be decomposed safely.');
        return ['gross' => $grand, 'tax' => $outputTax, 'revenue' => bcsub($grand, $outputTax, 4),
            'line_tax' => $headerTax, 'order_tax' => $this->money($sale->order_tax ?? 0)];
    }

    public function saleReturn(Returns $return): array
    {
        $fiscal = app(ZatcaIntegrationService::class)->returnAccountingComponents($return);
        $saleJournal = $this->originalSaleJournal((int) $return->sale_id);
        $policy = $this->policyOf($saleJournal);
        if ($fiscal !== null && $policy !== self::POLICY_V2) {
            throw new RuntimeException('A planned fiscal return requires its original tax-split-v2 sale journal; legacy gross-revenue reversal is not permitted.');
        }
        if ($policy === self::POLICY_LEGACY) return ['policy' => $policy, 'gross' => $this->money($return->grand_total), 'tax' => '0.0000', 'revenue' => $this->money($return->grand_total)];
        if ($policy !== self::POLICY_V2) throw new RuntimeException('Original sale accounting policy cannot be established safely.');

        $lineTax = $this->sum('product_returns', 'return_id', $return->id, 'tax');
        $headerTax = $this->money($return->total_tax ?? 0);
        $chargeTax = $fiscal['document_charge_tax'] ?? '0.0000';
        if (bccomp(bcadd($lineTax, $chargeTax, 4), $headerTax, 4) !== 0
            || ($fiscal !== null && isset($fiscal['line_tax']) && bccomp($lineTax, $fiscal['line_tax'], 4) !== 0)) {
            throw new RuntimeException('Return tax snapshot does not reconcile to its persisted product lines and fiscal charges.');
        }
        $tax = bcadd($headerTax, $this->money($return->order_tax ?? 0), 4);
        $gross = $this->money($return->grand_total);
        if ($fiscal !== null && ($gross !== $fiscal['gross'] || $tax !== $fiscal['tax'])) {
            throw new RuntimeException('Return accounting amounts differ from the stored fiscal plan.');
        }
        if (bccomp($tax, $gross, 4) > 0) throw new RuntimeException('Return tax snapshot cannot be decomposed safely.');

        $taxAccount = app(AccountingService::class)->getRoleAccountId(AccountingService::ROLE_OUTPUT_TAX_PAYABLE);
        $recognized = $this->lineAmount($saleJournal, $taxAccount, 'credit');
        $alreadyReturned = JournalEntry::where('source_type', Returns::class)->where('accounting_policy_version', self::POLICY_V2)
            ->whereIn('source_id', DB::table('returns')->where('sale_id', $return->sale_id)->where('id', '!=', $return->id)->select('id'))
            ->whereNull('related_journal_entry_id')->whereNotExists(fn ($q) => $q->selectRaw(1)->from('journal_entries as rev')->whereColumn('rev.related_journal_entry_id', 'journal_entries.id'))
            ->with('lines')->get()->reduce(fn ($sum, $journal) => bcadd($sum, $this->lineAmount($journal, $taxAccount, 'debit'), 4), '0.0000');
        if (bccomp(bcadd($alreadyReturned, $this->base($return, $tax), 4), $recognized, 4) > 0)
            throw new RuntimeException('Cumulative returned tax exceeds Output Tax originally recognized.');
        return ['policy' => self::POLICY_V2, 'gross' => $gross, 'tax' => $tax, 'revenue' => bcsub($gross, $tax, 4)];
    }

    /**
     * Sale exchanges are amendments to an existing sale, so their tax policy
     * follows the original sale journal rather than whichever policy happens
     * to be active on the day of the exchange. Product-exchange rows persist
     * the tax snapshot independently for returned and replacement lines.
     */
    public function saleExchange(SaleExchange $exchange): array
    {
        $saleJournal = $this->originalSaleJournal((int) $exchange->sale_id);
        $policy = $this->policyOf($saleJournal);
        if ($policy === 'unknown') $policy = $this->currentPolicy();

        $components = [];
        foreach (['returned', 'new'] as $type) {
            $query = DB::table('product_exchanges')->where('exchange_id', $exchange->id);
            if ($type === 'returned') {
                $query->whereIn('type', ['returned', 'return']);
            } else {
                $query->where('type', 'new');
            }
            $gross = $this->money($query->sum('total'));
            $tax = $this->money($query->sum('tax'));
            if (bccomp($tax, '0.0000', 4) < 0 || bccomp($tax, $gross, 4) > 0) {
                throw new RuntimeException('Sale exchange tax snapshot cannot be decomposed safely.');
            }
            $components[$type] = [
                'gross' => $gross,
                'tax' => $policy === self::POLICY_V2 ? $tax : '0.0000',
                'revenue' => $policy === self::POLICY_V2 ? bcsub($gross, $tax, 4) : $gross,
            ];
        }

        return ['policy' => $policy, ...$components];
    }

    /**
     * Decompose a purchase exactly as it was persisted. Product purchase totals
     * are gross of their line tax, while order tax is stored separately.
     */
    public function purchase(Purchase $purchase): array
    {
        $lineTax = $this->sum('product_purchases', 'purchase_id', $purchase->id, 'tax');
        $headerTax = $this->money($purchase->total_tax ?? 0);
        if (bccomp($lineTax, $headerTax, 4) !== 0) {
            throw new RuntimeException('Purchase tax snapshot does not reconcile to its persisted product lines.');
        }

        $itemGross = $this->money($purchase->total_cost ?? 0);
        $orderTax = $this->money($purchase->order_tax ?? 0);
        $tax = bcadd($headerTax, $orderTax, 4);
        $inventory = bcsub($itemGross, $headerTax, 4);
        $shipping = $this->money($purchase->shipping_cost ?? 0);
        $discount = $this->money($purchase->order_discount ?? 0);
        $gross = $this->money($purchase->grand_total ?? 0);
        $expectedGross = bcsub(bcadd(bcadd($itemGross, $orderTax, 4), $shipping, 4), $discount, 4);

        if (bccomp($tax, '0.0000', 4) < 0 || bccomp($inventory, '0.0000', 4) < 0
            || bccomp($expectedGross, $gross, 4) !== 0) {
            throw new RuntimeException('Purchase tax snapshot cannot be decomposed safely.');
        }

        return compact('gross', 'tax', 'inventory', 'shipping', 'discount');
    }

    /**
     * A purchase return must follow the accounting policy recorded by its
     * original purchase. This prevents a later policy cutover from changing
     * the economic meaning of a historical source document.
     */
    public function purchaseReturn(ReturnPurchase $return): array
    {
        $purchaseJournal = $this->originalPurchaseJournal((int) $return->purchase_id);
        $policy = $this->policyOf($purchaseJournal);
        if ($policy === 'unknown') {
            if ($this->currentPolicy() === self::POLICY_V2) {
                throw new RuntimeException('Original purchase accounting policy cannot be established safely.');
            }
            $policy = self::POLICY_LEGACY;
        }
        if ($policy === self::POLICY_LEGACY) {
            return ['policy' => $policy, 'gross' => $this->money($return->grand_total ?? 0), 'tax' => '0.0000',
                'purchase_return' => $this->money($return->grand_total ?? 0)];
        }
        if ($policy !== self::POLICY_V2) {
            throw new RuntimeException('Original purchase accounting policy cannot be established safely.');
        }

        $lineTax = $this->sum('purchase_product_return', 'return_id', $return->id, 'tax');
        $headerTax = $this->money($return->total_tax ?? 0);
        if (bccomp($lineTax, $headerTax, 4) !== 0) {
            throw new RuntimeException('Purchase return tax snapshot does not reconcile to its persisted product lines.');
        }

        $tax = bcadd($headerTax, $this->money($return->order_tax ?? 0), 4);
        $gross = $this->money($return->grand_total ?? 0);
        if (bccomp($tax, '0.0000', 4) < 0 || bccomp($tax, $gross, 4) > 0) {
            throw new RuntimeException('Purchase return tax snapshot cannot be decomposed safely.');
        }

        $inputVatAccount = app(AccountingService::class)->getRoleAccountId(AccountingService::ROLE_INPUT_VAT);
        $recognized = $this->lineAmount($purchaseJournal, $inputVatAccount, 'debit');
        $alreadyReturned = JournalEntry::where('source_type', ReturnPurchase::class)
            ->where('accounting_policy_version', self::POLICY_V2)
            ->whereIn('source_id', DB::table('return_purchases')->where('purchase_id', $return->purchase_id)
                ->where('id', '!=', $return->id)->select('id'))
            ->whereNull('related_journal_entry_id')
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('journal_entries as rev')
                ->whereColumn('rev.related_journal_entry_id', 'journal_entries.id'))
            ->with('lines')->get()
            ->reduce(fn ($sum, $journal) => bcadd($sum, $this->lineAmount($journal, $inputVatAccount, 'credit'), 4), '0.0000');
        if (bccomp(bcadd($alreadyReturned, $this->base($return, $tax), 4), $recognized, 4) > 0) {
            throw new RuntimeException('Cumulative returned input tax exceeds Input VAT originally recognized.');
        }

        return ['policy' => self::POLICY_V2, 'gross' => $gross, 'tax' => $tax,
            'purchase_return' => bcsub($gross, $tax, 4)];
    }

    public function policyOf(?JournalEntry $journal): string
    {
        if (!$journal) return 'unknown';
        if ($journal->accounting_policy_version === self::POLICY_V2) return self::POLICY_V2;
        if ($journal->accounting_policy_version) return $journal->accounting_policy_version;
        return $journal->lines()->count() === 2 ? self::POLICY_LEGACY : 'unknown';
    }

    private function originalSaleJournal(int $saleId): ?JournalEntry
    {
        return JournalEntry::where('source_type', Sale::class)->where('source_id', $saleId)
            ->whereNull('related_journal_entry_id')->whereNotExists(fn ($q) => $q->selectRaw(1)->from('journal_entries as rev')->whereColumn('rev.related_journal_entry_id', 'journal_entries.id'))
            ->with('lines')->latest('id')->first();
    }

    private function originalPurchaseJournal(int $purchaseId): ?JournalEntry
    {
        if ($purchaseId <= 0) {
            return null;
        }

        return JournalEntry::where('source_type', Purchase::class)->where('source_id', $purchaseId)
            ->whereNull('related_journal_entry_id')
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('journal_entries as rev')
                ->whereColumn('rev.related_journal_entry_id', 'journal_entries.id'))
            ->with('lines')->latest('id')->first();
    }

    private function currentPolicy(): string
    {
        $config = \App\Models\AccountingConfig::find(1);
        return $config?->sales_tax_policy_version === self::POLICY_V2
            && $config->sales_tax_policy_effective_at && $config->sales_tax_policy_effective_at->lte(now())
            ? self::POLICY_V2 : self::POLICY_LEGACY;
    }

    private function sum(string $table, string $foreignKey, int $id, string $column): string
    {
        return $this->money(DB::table($table)->where($foreignKey, $id)->sum($column));
    }

    private function money($amount): string { return \App\Services\Accounting\CurrencyNormalizationService::roundHalfUp((string) ($amount ?? 0), 4); }
    private function base($source, string $amount): string
    {
        return app(\App\Services\Accounting\CurrencyNormalizationService::class)->normalize($amount, $source->currency_id, $source->exchange_rate);
    }
    private function lineAmount(JournalEntry $journal, int $account, string $side): string
    {
        return $journal->lines->where('accounting_account_id', $account)->reduce(fn ($sum, $line) => bcadd($sum, (string) $line->{$side}, 4), '0.0000');
    }
}
