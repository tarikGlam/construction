<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Exceptions\UnbalancedJournalException;
use App\Exceptions\ClosedPeriodException;
use App\Exceptions\DuplicateJournalException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class JournalBuilder
{
    private $reference_no;
    private $entry_date;
    private $source_type;
    private $source_id;
    private $source_subtype;
    private $event_type;
    private $accounting_policy_version;
    private $note;
    private $created_by;
    private $warehouse_id;
    private $warehouse_scope_explicit = false;
    private $lines = [];

    public function __construct()
    {
        $this->entry_date = Carbon::now()->toDateString();
        $this->created_by = auth()->id();
    }

    public static function create(): self
    {
        return new self();
    }

    public function setReference(string $reference_no): self
    {
        $this->reference_no = $reference_no;
        return $this;
    }

    public function setDate($date): self
    {
        $this->entry_date = Carbon::parse($date)->toDateString();
        return $this;
    }

    public function setSource(string $source_type, int $source_id): self
    {
        $this->source_type = $source_type;
        $this->source_id = $source_id;
        return $this;
    }

    public function setSourceSubtype(string $source_subtype): self
    {
        $this->source_subtype = $source_subtype;
        return $this;
    }

    public function setEventType(string $event_type): self
    {
        $this->event_type = $event_type;
        return $this;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        return $this;
    }

    public function setCreatedBy(int $userId): self
    {
        $this->created_by = $userId;
        return $this;
    }

    public function setAccountingPolicyVersion(?string $version): self
    {
        $this->accounting_policy_version = $version;
        return $this;
    }

    public function setWarehouse(?int $warehouseId): self
    {
        $this->warehouse_id = $warehouseId;
        $this->warehouse_scope_explicit = true;
        return $this;
    }

    public function setGlobalWarehouseScope(): self
    {
        return $this->setWarehouse(null);
    }

    public function addDebit(int $accountId, $amount, ?string $description = null): self
    {
        if ($amount > 0) {
            $this->lines[] = [
                'accounting_account_id' => $accountId,
                'debit' => number_format((float) $amount, 4, '.', ''),
                'credit' => '0.0000',
                'description' => $description,
            ];
        }
        return $this;
    }

    public function addCredit(int $accountId, $amount, ?string $description = null): self
    {
        if ($amount > 0) {
            $this->lines[] = [
                'accounting_account_id' => $accountId,
                'debit' => '0.0000',
                'credit' => number_format((float) $amount, 4, '.', ''),
                'description' => $description,
            ];
        }
        return $this;
    }

    public function savePaymentState(): JournalEntry
    {
        return DB::transaction(function () {
            \App\Models\Payment::whereKey($this->source_id)->lockForUpdate()->firstOrFail();
            $history = JournalEntry::where('source_type', $this->source_type)
                ->where('source_id', $this->source_id)->with('lines')->orderBy('id')->get();
            $reversed = $history->pluck('related_journal_entry_id')->filter()->all();
            $active = $history->filter(fn ($entry) => !$entry->related_journal_entry_id && !in_array($entry->id, $reversed));
            if ($active->count() > 1) throw new \RuntimeException(__('integrity.payment_failed'));
            if ($existing = $active->first()) {
                $canonical = static function ($lines) {
                    return collect($lines)->map(fn ($line) => [
                        (int) $line['accounting_account_id'],
                        bcadd((string) $line['debit'], '0', 4),
                        bcadd((string) $line['credit'], '0', 4),
                    ])->sort()->values()->all();
                };
                if ($canonical($existing->lines->toArray()) !== $canonical($this->lines)
                    || $existing->entry_date->toDateString() !== \Carbon\Carbon::parse($this->entry_date)->toDateString()
                    || $existing->source_subtype !== $this->source_subtype) {
                    throw new \RuntimeException(__('integrity.payment_failed'));
                }
                return $existing;
            }
            $base = $this->event_type;
            $sequence = 2;
            while ($history->contains('event_type', $this->event_type)) {
                $this->event_type = $base.'_'.$sequence++;
            }
            if ($this->event_type !== $base) $this->reference_no .= '-V'.($sequence - 1);
            return $this->save();
        });
    }

    public function save(): JournalEntry
    {
        $this->validatePeriod();
        $this->validateBalance();
        $this->validateIdempotency();

        $this->resolveWarehouseAttribution();

        return DB::transaction(function () {
            if (!$this->reference_no) {
                $this->reference_no = 'JE-' . date('YmdHis') . '-' . rand(1000, 9999);
            }

            $attributes = [
                'reference_no' => $this->reference_no,
                'entry_date' => $this->entry_date,
                'source_type' => $this->source_type,
                'source_id' => $this->source_id,
                'source_subtype' => $this->source_subtype,
                'event_type' => $this->event_type,
                'accounting_policy_version' => $this->accounting_policy_version,
                'note' => $this->note,
                'created_by' => $this->created_by ?? \Illuminate\Support\Facades\Auth::id() ?? 1,
            ];
            if (Schema::hasColumn('journal_entries', 'warehouse_id')) {
                $attributes['warehouse_id'] = $this->warehouse_id;
            }

            $entry = JournalEntry::create($attributes);

            foreach ($this->lines as $line) {
                $entry->lines()->create($line);
            }

            return $entry;
        });
    }

    private function resolveWarehouseAttribution(): void
    {
        if (!Schema::hasColumn('journal_entries', 'warehouse_id') || $this->warehouse_scope_explicit) {
            return;
        }

        $attribution = app(JournalWarehouseResolver::class)->resolve(
            $this->source_type,
            $this->source_id,
            $this->source_subtype,
            $this->event_type,
        );

        if ($attribution->requiresWarehouse && !$attribution->warehouseId) {
            throw new \RuntimeException('Journal warehouse attribution failed: '.$attribution->reason);
        }

        $this->warehouse_id = $attribution->warehouseId;
    }

    private function validatePeriod(): void
    {
        $period = AccountingPeriod::where('start_date', '<=', $this->entry_date)
            ->where('end_date', '>=', $this->entry_date)
            ->first();

        if ($period && $period->is_closed) {
            throw new ClosedPeriodException();
        }
    }

    private function validateBalance(): void
    {
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        foreach ($this->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line['debit'], 4);
            $totalCredit = bcadd($totalCredit, $line['credit'], 4);
        }

        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            throw new UnbalancedJournalException("Journal entry unbalanced. Debits: {$totalDebit}, Credits: {$totalCredit}");
        }
    }

    private function validateIdempotency(): void
    {
        if ($this->source_type && $this->source_id && $this->event_type) {
            $exists = JournalEntry::where('source_type', $this->source_type)
                ->where('source_id', $this->source_id)
                ->where('event_type', $this->event_type)
                ->exists();

            if ($exists) {
                throw new DuplicateJournalException();
            }
        }
    }

    /**
     * Strictly enforce line-by-line reversal.
     */
    public static function reverse(JournalEntry $originalEntry, string $eventSuffix = '_reversed', ?string $note = null): JournalEntry
    {
        $builder = self::create()
            ->setSource($originalEntry->source_type, $originalEntry->source_id)
            ->setSourceSubtype($originalEntry->source_subtype ?? '')
            ->setEventType($originalEntry->event_type . $eventSuffix)
            ->setAccountingPolicyVersion($originalEntry->accounting_policy_version)
            ->setReference($originalEntry->reference_no . '-REV')
            ->setDate(now()->toDateString())
            ->setNote($note ?? 'Reversal of ' . $originalEntry->reference_no)
            ->setWarehouse($originalEntry->warehouse_id);

        foreach ($originalEntry->lines as $line) {
            if ($line->debit > 0) {
                $builder->addCredit($line->accounting_account_id, $line->debit, 'Reversal: ' . $line->description);
            }
            if ($line->credit > 0) {
                $builder->addDebit($line->accounting_account_id, $line->credit, 'Reversal: ' . $line->description);
            }
        }

        $reversal = $builder->save();

        if (Schema::hasColumn('journal_entries', 'related_journal_entry_id')) {
            $reversal->related_journal_entry_id = $originalEntry->id;
            $reversal->save();
        }

        return $reversal;
    }
}
