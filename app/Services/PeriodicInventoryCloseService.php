<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\PeriodicInventoryClose;
use App\Models\AccountingConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PeriodicInventoryCloseService
{
    public function __construct(
        private InventoryValuationService $valuation,
        private AccountingService $accounting
    ) {}

    public function preview(string $periodStart, string $periodEnd): array
    {
        $boundary = $this->effectivePeriodStart();
        $periodStart = $boundary['date'];
        $periodEndDate = Carbon::parse($periodEnd)->toDateString();
        $today = Carbon::today()->toDateString();
        $periodAvailable = $periodStart <= $periodEndDate;
        $valuation = $this->valuation->currentValuation();
        $inventoryId = $this->accounting->getRoleAccountId(AccountingService::ROLE_INVENTORY);
        $book = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.accounting_account_id', $inventoryId)
            ->whereDate('journal_entries.entry_date', '<=', $periodEndDate)
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) balance')
            ->value('balance');

        $active = PeriodicInventoryClose::where('period_start', $periodStart)
            ->where('period_end', $periodEndDate)->where('scope', 'company')
            ->whereIn('status', ['posted', 'zero'])->latest('id')->first();
        if (!$periodAvailable) {
            $active = PeriodicInventoryClose::where('scope', 'company')
                ->whereIn('status', ['posted', 'zero'])
                ->where('period_end', '>=', $periodEndDate)
                ->orderByDesc('period_end')
                ->latest('id')
                ->first();
        }
        $blocking = collect();
        if ($periodStart > $periodEndDate) $blocking->push('period_start_after_end');
        if ($periodEndDate !== $today) $blocking->push('historical_cutoff_unsupported');
        if ($valuation['missing_cost']->isNotEmpty()) $blocking->push('missing_cost');
        if ($valuation['negative_stock']->isNotEmpty()) $blocking->push('negative_stock');
        if ($valuation['quantity_mismatches']->isNotEmpty()) $blocking->push('quantity_mismatch');
        if (AccountingPeriod::where('start_date', '<=', $periodEndDate)->where('end_date', '>=', $periodEndDate)->where('is_closed', true)->exists()) {
            $blocking->push('accounting_period_closed');
        }
        $adjustment = round($book - $valuation['value'], 4);
        $inventory = DB::table('accounting_accounts')->where('id', $inventoryId)->first(['id', 'code', 'name']);
        $cogsId = $this->accounting->getRoleAccountId(AccountingService::ROLE_COST_OF_GOODS_SOLD);
        $cogs = DB::table('accounting_accounts')->where('id', $cogsId)->first(['id', 'code', 'name']);
        $amount = abs($adjustment);
        $proposedEntry = $amount < 0.0001 ? [] : ($adjustment > 0 ? [
            'debit' => ['id' => $cogsId, 'code' => $cogs?->code, 'name' => $cogs?->name, 'amount' => $amount],
            'credit' => ['id' => $inventoryId, 'code' => $inventory?->code, 'name' => $inventory?->name, 'amount' => $amount],
        ] : [
            'debit' => ['id' => $inventoryId, 'code' => $inventory?->code, 'name' => $inventory?->name, 'amount' => $amount],
            'credit' => ['id' => $cogsId, 'code' => $cogs?->code, 'name' => $cogs?->name, 'amount' => $amount],
        ]);

        return [
            'period_start' => Carbon::parse($periodStart)->toDateString(),
            'period_start_basis' => $boundary['basis'],
            'is_first_close' => $boundary['basis'] === 'accounting_activation',
            'period_available' => $periodAvailable,
            'covered_through' => $active?->period_end?->toDateString(),
            'period_end' => $periodEndDate,
            'book_inventory' => round($book, 4),
            'operational_inventory' => $valuation['value'],
            'adjustment' => $adjustment,
            'formula' => ['accounting_inventory' => round($book, 4), 'operational_inventory' => $valuation['value'], 'difference' => $adjustment],
            'proposed_entry' => $proposedEntry,
            'breakdown' => [
                'warehouses' => $valuation['warehouses']->take(25)->values(),
                'top_contributors' => $valuation['items']->sortByDesc(fn ($item) => abs((float) $item->value))->take(10)->values(),
                'negative_stock' => $valuation['negative_stock']->take(10)->values(),
                'missing_cost' => $valuation['missing_cost']->take(10)->values(),
                'quantity_mismatches' => $valuation['quantity_mismatches']->take(10)->values(),
                'sample_limit' => 10,
            ],
            'valuation_basis' => [
                'valuation_date' => $today,
                'quantity_basis' => 'Current quantities stored per product, variant, and warehouse',
                'cost_basis' => 'Weighted average of inventory receipts from purchases, production output, and positive stock adjustments, with recorded product cost fallback where supported',
                'warehouse_scope' => 'All company warehouses',
                'included_types' => 'Products represented in product_warehouse',
                'variant_batch_treatment' => 'Variants are valued separately; current batch quantities are included in their product/variant warehouse quantity',
                'rounding' => 'Item calculations retain four decimals; the page displays two decimals',
                'historical_reconstruction_supported' => false,
            ],
            'valuation' => $valuation,
            'blocking_errors' => $blocking,
            'can_post' => $periodAvailable && $blocking->isEmpty() && !$active,
            'active_close' => $active,
            'scope' => 'company',
            'calculated_at' => now(),
        ];
    }

    public function effectivePeriodStart(): array
    {
        $last = PeriodicInventoryClose::query()->where('scope', 'company')
            ->whereIn('status', ['posted', 'zero'])->orderByDesc('period_end')->first();
        if ($last) {
            return ['date' => $last->period_end->copy()->addDay()->toDateString(), 'basis' => 'previous_successful_close'];
        }

        $config = AccountingConfig::find(1);
        $activation = $config?->cutover_at ?: $config?->start_date;
        if (!$activation) throw new RuntimeException('inventory_close_missing_accounting_activation');
        return ['date' => Carbon::parse($activation)->toDateString(), 'basis' => 'accounting_activation'];
    }

    public function post(string $periodStart, string $periodEnd, ?int $userId = null): PeriodicInventoryClose
    {
        return DB::transaction(function () use ($periodStart, $periodEnd, $userId) {
            $alreadyClosedThroughEnd = PeriodicInventoryClose::where('period_end', Carbon::parse($periodEnd)->toDateString())
                ->where('scope', 'company')->whereIn('status', ['posted', 'zero'])->lockForUpdate()->first();
            if ($alreadyClosedThroughEnd) return $alreadyClosedThroughEnd;
            $existing = PeriodicInventoryClose::where('period_start', $periodStart)->where('period_end', $periodEnd)
                ->where('scope', 'company')->whereIn('status', ['posted', 'zero'])->lockForUpdate()->first();
            if ($existing) return $existing;

            $preview = $this->preview($periodStart, $periodEnd);
            if ($preview['blocking_errors']->isNotEmpty()) {
                throw new RuntimeException('inventory_close_blocked:' . $preview['blocking_errors']->implode(','));
            }

            $close = PeriodicInventoryClose::create([
                'period_start' => $preview['period_start'], 'period_end' => $preview['period_end'],
                'posting_date' => $preview['period_end'], 'scope' => 'company',
                'active_key' => 'company:' . $preview['period_start'] . ':' . $preview['period_end'],
                'status' => abs($preview['adjustment']) < 0.0001 ? 'zero' : 'posted',
                'book_inventory' => $preview['book_inventory'],
                'operational_inventory' => $preview['operational_inventory'],
                'adjustment' => $preview['adjustment'],
                'calculation_basis' => [
                    'method' => $preview['valuation']['method'],
                    'warehouse_totals' => $preview['valuation']['warehouses']->values()->all(),
                    'item_count' => $preview['valuation']['items']->count(),
                    'items' => $preview['valuation']['items']->map(fn ($item) => [
                        'product_id' => (int) $item->product_id,
                        'variant_id' => (int) $item->variant_id,
                        'warehouse_id' => (int) $item->warehouse_id,
                        'quantity' => (float) $item->qty,
                        'unit_cost' => (float) $item->cost,
                        'cost_source' => $item->source,
                        'value' => round((float) $item->value, 4),
                    ])->values()->all(),
                ],
                'created_by' => $userId, 'calculated_at' => now(), 'posted_at' => now(),
            ]);

            if (abs($preview['adjustment']) >= 0.0001) {
                $inventory = $this->accounting->getRoleAccountId(AccountingService::ROLE_INVENTORY);
                $cogs = $this->accounting->getRoleAccountId(AccountingService::ROLE_COST_OF_GOODS_SOLD);
                $builder = JournalBuilder::create()->setReference('INV-CLOSE-' . $close->id)
                    ->setDate($preview['period_end'])->setSource(PeriodicInventoryClose::class, $close->id)
                    ->setEventType('periodic_inventory_close')
                    ->setNote('Periodic inventory close ' . $preview['period_start'] . ' to ' . $preview['period_end']);
                if ($preview['adjustment'] > 0) {
                    $builder->addDebit($cogs, $preview['adjustment'], 'Periodic COGS')
                        ->addCredit($inventory, $preview['adjustment'], 'Ending inventory adjustment');
                } else {
                    $amount = abs($preview['adjustment']);
                    $builder->addDebit($inventory, $amount, 'Ending inventory adjustment')
                        ->addCredit($cogs, $amount, 'Periodic COGS true-up');
                }
                $journal = $builder->save();
                $close->update(['journal_entry_id' => $journal->id]);
            }

            return $close->fresh();
        });
    }

    public function reverse(PeriodicInventoryClose $close): PeriodicInventoryClose
    {
        return DB::transaction(function () use ($close) {
            $close = PeriodicInventoryClose::lockForUpdate()->findOrFail($close->id);
            if ($close->status === 'reversed') return $close;
            $reversal = $close->journal_entry_id
                ? JournalBuilder::reverse($close->journalEntry, '_reversed', 'Periodic inventory close reversal')
                : null;
            $close->update([
                'status' => 'reversed', 'active_key' => null,
                'reversal_journal_entry_id' => $reversal?->id, 'reversed_at' => now(),
            ]);
            return $close->fresh();
        });
    }

    public function recalculate(PeriodicInventoryClose $close, ?int $userId = null): PeriodicInventoryClose
    {
        return DB::transaction(function () use ($close, $userId) {
            $start = $close->period_start->toDateString();
            $end = $close->period_end->toDateString();
            $this->reverse($close);
            return $this->post($start, $end, $userId);
        });
    }
}
