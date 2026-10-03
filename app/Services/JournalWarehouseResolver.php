<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JournalWarehouseResolver
{
    /**
     * Resolve one authoritative warehouse for an indivisible journal entry.
     * Unknown source types are intentionally unresolved: future transactional
     * workflows must opt into a documented policy instead of disappearing from
     * warehouse reports.
     */
    public function resolve(
        ?string $sourceType,
        ?int $sourceId,
        ?string $sourceSubtype = null,
        ?string $eventType = null,
        ?int $relatedJournalEntryId = null,
    ): JournalWarehouseAttribution {
        if ($relatedJournalEntryId && Schema::hasColumn('journal_entries', 'warehouse_id')) {
            $warehouseId = DB::table('journal_entries')->where('id', $relatedJournalEntryId)->value('warehouse_id');
            if ($warehouseId) {
                return JournalWarehouseAttribution::attributed((int) $warehouseId, 'inherited from original journal');
            }
        }

        $type = $this->normalize($sourceType);
        if (!$type || !$sourceId) {
            return JournalWarehouseAttribution::unresolved('missing journal source identity');
        }

        $direct = [
            'sale' => ['sales', 'warehouse_id'],
            'purchase' => ['purchases', 'warehouse_id'],
            'returns' => ['returns', 'warehouse_id'],
            'return' => ['returns', 'warehouse_id'],
            'salereturn' => ['returns', 'warehouse_id'],
            'returnpurchase' => ['return_purchases', 'warehouse_id'],
            'purchasereturn' => ['return_purchases', 'warehouse_id'],
            'expense' => ['expenses', 'warehouse_id'],
            'income' => ['incomes', 'warehouse_id'],
            'saleexchange' => ['sale_exchanges', 'warehouse_id'],
            'adjustment' => ['adjustments', 'warehouse_id'],
            'damagestock' => ['damage_stocks', 'warehouse_id'],
        ];

        if (isset($direct[$type])) {
            [$table, $column] = $direct[$type];
            return $this->fromTable($table, $sourceId, $column, $type);
        }

        if ($type === 'payment') {
            return $this->resolvePayment($sourceId);
        }

        if ($type === 'deposit' && $sourceSubtype === 'customer_opening_deposit') {
            return JournalWarehouseAttribution::global('customer opening deposit');
        }

        if ($type === 'deposit') {
            return $this->fromTable('deposits', $sourceId, 'warehouse_id', 'deposit');
        }

        if ($type === 'payroll') {
            if (!Schema::hasTable('payrolls') || !Schema::hasTable('employees')) {
                return JournalWarehouseAttribution::unresolved('payroll warehouse relationship is unavailable');
            }
            $warehouseId = DB::table('payrolls')
                ->join('employees', 'employees.id', '=', 'payrolls.employee_id')
                ->where('payrolls.id', $sourceId)
                ->value('employees.warehouse_id');

            return $warehouseId
                ? JournalWarehouseAttribution::attributed((int) $warehouseId, 'payroll employee warehouse')
                : JournalWarehouseAttribution::unresolved('payroll employee has no warehouse');
        }

        // These entries are company-wide by current accounting policy. Stock
        // and money transfers cannot be assigned to one warehouse without
        // splitting a balanced journal or introducing clearing accounts.
        if (in_array($type, [
            'activation', 'account', 'customer', 'supplier',
            'moneytransfer', 'transfer', 'periodicinventoryclose', 'giftcard', 'giftcardrecharge',
        ], true)) {
            return JournalWarehouseAttribution::global('documented company-wide journal policy');
        }

        return JournalWarehouseAttribution::unresolved(
            sprintf('unsupported source type %s (%s)', $sourceType, $eventType ?: 'unknown event')
        );
    }

    private function resolvePayment(int $paymentId): JournalWarehouseAttribution
    {
        if (!Schema::hasTable('payments')) {
            return JournalWarehouseAttribution::unresolved('payments table is unavailable');
        }

        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (!$payment) {
            return $this->inheritPriorSourceWarehouse('payment', $paymentId)
                ?? JournalWarehouseAttribution::unresolved('payment source no longer exists');
        }

        $relationships = [
            ['return_id', 'returns'],
            ['purchase_return_id', 'return_purchases'],
            ['sale_id', 'sales'],
            ['purchase_id', 'purchases'],
        ];

        foreach ($relationships as [$foreignKey, $table]) {
            if (!empty($payment->{$foreignKey})) {
                return $this->fromTable($table, (int) $payment->{$foreignKey}, 'warehouse_id', 'payment '.$foreignKey);
            }
        }

        if (!empty($payment->service_job_id) && Schema::hasTable('service_jobs')) {
            $serviceJob = DB::table('service_jobs')->where('id', $payment->service_job_id)->first();
            if ($serviceJob && isset($serviceJob->warehouse_id) && $serviceJob->warehouse_id) {
                return JournalWarehouseAttribution::attributed((int) $serviceJob->warehouse_id, 'service job warehouse');
            }
            if ($serviceJob && isset($serviceJob->sale_id) && $serviceJob->sale_id) {
                return $this->fromTable('sales', (int) $serviceJob->sale_id, 'warehouse_id', 'service job sale');
            }
        }

        return JournalWarehouseAttribution::unresolved('payment has no warehouse-bearing parent transaction');
    }

    private function fromTable(string $table, int $id, string $column, string $reason): JournalWarehouseAttribution
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return JournalWarehouseAttribution::unresolved("{$reason} warehouse relationship is unavailable");
        }

        $warehouseId = DB::table($table)->where('id', $id)->value($column);

        return $warehouseId
            ? JournalWarehouseAttribution::attributed((int) $warehouseId, "{$reason} warehouse")
            : JournalWarehouseAttribution::unresolved("{$reason} has no warehouse");
    }

    private function inheritPriorSourceWarehouse(string $sourceType, int $sourceId): ?JournalWarehouseAttribution
    {
        if (!Schema::hasColumn('journal_entries', 'warehouse_id')) {
            return null;
        }

        $warehouseId = DB::table('journal_entries')
            ->where(function ($query) use ($sourceType) {
                $query->whereRaw('LOWER(source_type) = ?', [$sourceType])
                    ->orWhereRaw('LOWER(source_type) LIKE ?', ['%\\'.$sourceType]);
            })
            ->where('source_id', $sourceId)
            ->whereNotNull('warehouse_id')
            ->value('warehouse_id');

        return $warehouseId
            ? JournalWarehouseAttribution::attributed((int) $warehouseId, 'inherited from prior source journal')
            : null;
    }

    private function normalize(?string $sourceType): string
    {
        $sourceType = strtolower(trim((string) $sourceType));
        $sourceType = str_replace(['/', '\\'], '\\', $sourceType);
        $basename = str_contains($sourceType, '\\')
            ? substr($sourceType, strrpos($sourceType, '\\') + 1)
            : $sourceType;

        return preg_replace('/[^a-z0-9]/', '', $basename) ?: '';
    }
}
