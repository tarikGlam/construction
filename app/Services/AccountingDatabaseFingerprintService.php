<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AccountingDatabaseFingerprintService
{
    private const CRITICAL_TABLES = [
        'migrations', 'accounting_accounts', 'account_mappings', 'journal_entries',
        'journal_lines', 'accounting_sync_queue', 'accounting_configs',
        'accounting_activation_sessions', 'accounts', 'sales', 'purchases',
        'returns', 'return_purchases', 'payments', 'expenses',
    ];

    public function capture(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $database = $db->getDatabaseName();
        $tables = $db->table('information_schema.tables')
            ->where('table_schema', $database)
            ->where('table_type', 'BASE TABLE')
            ->orderBy('table_name')
            ->get(['TABLE_NAME'])
            ->map(function ($row): string {
                $values = array_change_key_case((array) $row, CASE_LOWER);

                return (string) $values['table_name'];
            })
            ->all();

        $critical = [];
        foreach (self::CRITICAL_TABLES as $table) {
            $exists = in_array($table, $tables, true);
            $critical[$table] = [
                'exists' => $exists,
                'rows' => $exists ? $db->table($table)->count() : null,
            ];
        }

        $columns = $db->table('information_schema.columns')
            ->where('table_schema', $database)
            ->whereIn('table_name', self::CRITICAL_TABLES)
            ->orderBy('table_name')->orderBy('ordinal_position')
            ->get(['TABLE_NAME', 'COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE'])
            ->map(fn ($row) => array_change_key_case((array) $row, CASE_LOWER))->all();

        $journal = $critical['journal_lines']['exists']
            ? (array) $db->table('journal_lines')->selectRaw(
                'COALESCE(SUM(debit), 0) total_debits, COALESCE(SUM(credit), 0) total_credits'
            )->first()
            : ['total_debits' => null, 'total_credits' => null];

        $identity = [
            'table_count' => count($tables),
            'tables' => $tables,
            'critical' => $critical,
            'critical_schema_sha256' => hash('sha256', json_encode($columns, JSON_UNESCAPED_SLASHES)),
            'journal_totals' => [
                'debits' => isset($journal['total_debits']) ? number_format((float) $journal['total_debits'], 4, '.', '') : null,
                'credits' => isset($journal['total_credits']) ? number_format((float) $journal['total_credits'], 4, '.', '') : null,
            ],
        ];

        return $identity + [
            'sha256' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES)),
        ];
    }
}
