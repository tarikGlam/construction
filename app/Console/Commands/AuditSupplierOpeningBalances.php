<?php

namespace App\Console\Commands;

use App\Services\SupplierOpeningBalanceAuditService;
use Illuminate\Console\Command;

class AuditSupplierOpeningBalances extends Command
{
    protected $signature = 'salepro:audit-supplier-opening-balances {--json : Emit machine-readable JSON}';
    protected $description = 'Read-only audit of supplier opening purchases, payments and journals';

    public function handle(SupplierOpeningBalanceAuditService $audit): int
    {
        $rows = $audit->audit();
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Supplier ID', 'Name', 'Condition', 'Supplier opening', 'Opening purchases', 'Paid', 'Remaining', 'Journal', 'Remediation'],
                array_map(fn ($row) => [
                    $row['supplier_id'], $row['supplier_name'], $row['condition'],
                    $row['supplier_opening_balance'], implode(', ', $row['opening_purchase_amounts']),
                    $row['paid_amount'], $row['remaining_payable'], $row['journal_state'], $row['remediation'],
                ], $rows)
            );
            $this->info(count($rows) . ' finding(s). No data was changed.');
        }
        return self::SUCCESS;
    }
}
