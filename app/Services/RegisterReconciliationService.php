<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\PosSetting;
use App\Models\RegisterReconciliation;
use App\Models\Sale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RegisterReconciliationService
{
    private const SCALE = 4;

    public function refundMethods(): array
    {
        return collect($this->configuredMethods())
            ->map(fn (string $method) => [
                'key' => $this->methodKey($method),
                'label' => $this->methodLabel($method),
            ])
            ->unique('key')
            ->values()
            ->all();
    }

    public function expectedTenders(CashRegister $register, bool $lockMovements = false): Collection
    {
        $tenders = collect();

        foreach ($this->configuredMethods() as $method) {
            $this->ensureTender($tenders, $method);
        }
        $this->add($tenders, 'Cash', $register->cash_in_hand ?? 0);

        $payments = Payment::where('cash_register_id', $register->id)
            ->where(fn ($active) => $active->whereNull('accounting_status')
                ->orWhereNotIn('accounting_status', ['reversed', 'voided']))
            ->where(fn ($sale) => $sale->whereNull('sale_id')
                ->orWhereNotIn('sale_id', Sale::onlyTrashed()->select('id')))
            ->when($lockMovements, fn ($query) => $query->lockForUpdate())
            ->get(['amount', 'exchange_rate', 'paying_method', 'sale_id', 'purchase_id', 'return_id', 'purchase_return_id']);

        foreach ($payments as $payment) {
            $amount = BigDecimal::of((string) $payment->amount)
                ->dividedBy((string) (($payment->exchange_rate ?? 1) ?: 1), self::SCALE, RoundingMode::HALF_UP);
            $isReturn = $payment->return_id !== null || $payment->purchase_return_id !== null;

            if ($payment->purchase_id !== null && $this->methodKey($payment->paying_method ?: 'Not recorded') !== 'cash') {
                continue;
            }

            $isOutflow = ($payment->sale_id !== null && $isReturn)
                || ($payment->purchase_id !== null && !$isReturn);
            $this->add($tenders, $payment->paying_method ?: 'Not recorded', $isOutflow ? $amount->negated() : $amount);
        }

        $expenseQuery = Expense::where('cash_register_id', $register->id);
        $incomeQuery = Income::where('cash_register_id', $register->id);
        if ($lockMovements) {
            $expenseQuery->lockForUpdate();
            $incomeQuery->lockForUpdate();
        }
        $expenses = $expenseQuery->get(['amount']);
        $incomes = $incomeQuery->get(['amount']);
        $expenseTotal = $expenses->reduce(fn (BigDecimal $sum, $expense) => $sum->plus((string) $expense->amount), BigDecimal::zero());
        $incomeTotal = $incomes->reduce(fn (BigDecimal $sum, $income) => $sum->plus((string) $income->amount), BigDecimal::zero());
        $this->add($tenders, 'Cash', $expenseTotal->negated());
        $this->add($tenders, 'Cash', $incomeTotal);

        return $tenders->sortBy(fn ($tender) => $tender['method_key'] === 'cash' ? '' : $tender['method_label'])
            ->values();
    }

    public function close(CashRegister $register, array $countedTenders, int $closedByUserId, ?string $closingNote = null): RegisterReconciliation
    {
        return DB::transaction(function () use ($register, $countedTenders, $closedByUserId, $closingNote) {
            $register = CashRegister::withoutGlobalScopes()->lockForUpdate()->findOrFail($register->id);
            if (!$register->status || $register->reconciliation()->exists()) {
                throw new RuntimeException('Cash register is already closed.');
            }

            $expectedTenders = $this->expectedTenders($register, true);
            $submitted = collect($countedTenders)->keyBy('method_key');
            $errors = [];
            $rows = collect();

            foreach ($expectedTenders as $expected) {
                $input = $submitted->get($expected['method_key']);
                if (!$input || !array_key_exists('counted_amount', $input) || !is_numeric($input['counted_amount'])) {
                    $errors["tenders.{$expected['method_key']}.counted_amount"] = __('db.Enter a valid counted amount for every tender.');
                    continue;
                }

                $counted = $this->money($input['counted_amount']);
                $variance = $counted->minus($expected['expected_amount']);
                $reason = trim((string) ($input['variance_reason'] ?? ''));
                if (!$variance->isZero() && $reason === '') {
                    $errors["tenders.{$expected['method_key']}.variance_reason"] = __('db.A variance reason is required when counted and expected amounts differ.');
                }
                $rows->push($expected + [
                    'counted_amount' => (string) $counted,
                    'variance_amount' => (string) $variance,
                    'variance_reason' => $reason ?: null,
                ]);
            }

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $expectedTotal = $rows->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['expected_amount']), BigDecimal::zero()->toScale(self::SCALE));
            $countedTotal = $rows->reduce(fn (BigDecimal $sum, array $row) => $sum->plus($row['counted_amount']), BigDecimal::zero()->toScale(self::SCALE));
            $closedAt = now();
            $reconciliation = RegisterReconciliation::create([
                'cash_register_id' => $register->id,
                'warehouse_id' => $register->warehouse_id,
                'opened_by_user_id' => $register->user_id,
                'closed_by_user_id' => $closedByUserId,
                'opened_at' => $register->created_at,
                'closed_at' => $closedAt,
                'opening_cash' => $this->money($register->cash_in_hand),
                'expected_total' => $expectedTotal,
                'counted_total' => $countedTotal,
                'variance_total' => $countedTotal->minus($expectedTotal),
                'closing_note' => $closingNote,
            ]);
            $reconciliation->tenders()->createMany($rows->all());

            $cashRow = $rows->firstWhere('method_key', 'cash');
            $register->forceFill([
                'closing_balance' => (string) $expectedTotal,
                'actual_cash' => $cashRow['counted_amount'] ?? '0.0000',
                'closed_by_user_id' => $closedByUserId,
                'closed_at' => $closedAt,
                'closing_note' => $closingNote,
                'status' => false,
            ])->save();

            return $reconciliation->load('tenders');
        }, 3);
    }

    private function configuredMethods(): array
    {
        $methods = array_filter(array_map('trim', explode(',', (string) PosSetting::value('payment_options'))));
        return array_values(array_unique(array_merge(['Cash'], $methods)));
    }

    private function add(Collection $tenders, string $method, mixed $amount): void
    {
        $key = $this->methodKey($method);
        $this->ensureTender($tenders, $method);
        $row = $tenders->get($key);
        $row['expected_amount'] = (string) BigDecimal::of($row['expected_amount'])->plus($this->money($amount));
        $tenders->put($key, $row);
    }

    private function ensureTender(Collection $tenders, string $method): void
    {
        $key = $this->methodKey($method);
        if (!$tenders->has($key)) {
            $tenders->put($key, [
                'method_key' => $key,
                'method_label' => $this->methodLabel($method),
                'expected_amount' => '0.0000',
            ]);
        }
    }

    private function methodKey(string $method): string
    {
        $normalized = strtolower(trim(str_replace(['_', '-'], ' ', $method)));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return match ($normalized) {
            'card', 'credit', 'credit card' => 'credit_card',
            'gift card' => 'gift_card',
            default => str_replace(' ', '_', $normalized ?: 'not_recorded'),
        };
    }

    private function methodLabel(string $method): string
    {
        return match ($this->methodKey($method)) {
            'credit_card' => 'Credit Card',
            'gift_card' => 'Gift Card',
            'upi' => 'UPI',
            'eft' => 'EFT',
            default => ucwords(trim(str_replace(['_', '-'], ' ', $method))) ?: 'Not recorded',
        };
    }

    private function money(mixed $amount): BigDecimal
    {
        return BigDecimal::of((string) ($amount ?? 0))->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
