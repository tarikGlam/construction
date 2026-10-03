<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class StandardSemanticAccountService
{
    public const DEFINITIONS = [
        AccountingService::ROLE_CASH => ['code' => '1000', 'name' => 'Cash & Bank', 'account_type' => 'asset', 'is_cash_account' => true],
        AccountingService::ROLE_ACCOUNTS_RECEIVABLE => ['code' => '1100', 'name' => 'Accounts Receivable', 'account_type' => 'asset'],
        AccountingService::ROLE_EMPLOYEE_ADVANCE_RECEIVABLE => ['code' => '1150', 'name' => 'Employee Advance Receivable', 'account_type' => 'asset'],
        AccountingService::ROLE_INVENTORY => ['code' => '1200', 'name' => 'Inventory', 'account_type' => 'asset'],
        AccountingService::ROLE_INPUT_VAT => ['code' => '1250', 'name' => 'Input VAT', 'account_type' => 'asset'],
        AccountingService::ROLE_ACCOUNTS_PAYABLE => ['code' => '2100', 'name' => 'Accounts Payable', 'account_type' => 'liability'],
        AccountingService::ROLE_OUTPUT_TAX_PAYABLE => ['code' => '2200', 'name' => 'Output Tax Payable', 'account_type' => 'liability'],
        AccountingService::ROLE_CUSTOMER_DEPOSIT => ['code' => '2210', 'name' => 'Customer Deposits', 'account_type' => 'liability'],
        AccountingService::ROLE_REWARDS_LIABILITY => ['code' => '2220', 'name' => 'Customer Rewards', 'account_type' => 'liability'],
        AccountingService::ROLE_GIFT_CARD_LIABILITY => ['code' => '2250', 'name' => 'Gift Card Liability', 'account_type' => 'liability'],
        AccountingService::ROLE_OPENING_EQUITY => ['code' => '3900', 'name' => 'Opening Balance Equity', 'account_type' => 'equity'],
        AccountingService::ROLE_SALES_REVENUE => ['code' => '4100', 'name' => 'Sales Revenue', 'account_type' => 'revenue'],
        AccountingService::ROLE_SALES_RETURNS => ['code' => '4150', 'name' => 'Sales Returns', 'account_type' => 'revenue'],
        AccountingService::ROLE_SALES_DISCOUNT => ['code' => '4160', 'name' => 'Sales Discounts', 'account_type' => 'revenue'],
        AccountingService::ROLE_OTHER_INCOME => ['code' => '4300', 'name' => 'Other Income', 'account_type' => 'revenue'],
        AccountingService::ROLE_COST_OF_GOODS_SOLD => ['code' => '5000', 'name' => 'Cost of Goods Sold', 'account_type' => 'cogs'],
        AccountingService::ROLE_PAYROLL_EXPENSE => ['code' => '5100', 'name' => 'Payroll Expense', 'account_type' => 'expense'],
        AccountingService::ROLE_PURCHASE_RETURNS => ['code' => '5150', 'name' => 'Purchase Returns', 'account_type' => 'expense'],
        AccountingService::ROLE_FREIGHT_IN => ['code' => '5200', 'name' => 'Freight In', 'account_type' => 'expense'],
        AccountingService::ROLE_REWARDS_EXPENSE => ['code' => '5210', 'name' => 'Rewards Expense', 'account_type' => 'expense'],
        AccountingService::ROLE_PURCHASE_DISCOUNT => ['code' => '5300', 'name' => 'Purchase Discounts', 'account_type' => 'expense'],
        AccountingService::ROLE_OPERATING_EXPENSE => ['code' => '6100', 'name' => 'General Expense', 'account_type' => 'expense'],
    ];

    public function inspect(): array
    {
        $schemaIssues = collect(['accounting_accounts', 'account_mappings'])
            ->reject(fn (string $table) => Schema::hasTable($table))
            ->values()->all();
        $accountsToCreate = [];
        $mappingsToCreate = [];
        $preservedMappings = [];
        $conflicts = [];

        if ($schemaIssues) {
            return [
                'schema_issues' => $schemaIssues,
                'accounts_to_create' => $accountsToCreate,
                'mappings_to_create' => $mappingsToCreate,
                'preserved_mappings' => $preservedMappings,
                'conflicts' => $conflicts,
            ];
        }

        $roleNames = array_keys(self::DEFINITIONS);
        $duplicateUses = AccountMapping::query()
            ->where('mapped_id', 0)->whereIn('mapped_type', $roleNames)
            ->select('accounting_account_id', DB::raw('COUNT(*) as role_count'), DB::raw('GROUP_CONCAT(mapped_type ORDER BY mapped_type) as roles'))
            ->groupBy('accounting_account_id')->havingRaw('COUNT(*) > 1')->get();
        foreach ($duplicateUses as $duplicate) {
            $conflicts[] = [
                'kind' => 'duplicate_semantic_use',
                'accounting_account_id' => (int) $duplicate->accounting_account_id,
                'roles' => explode(',', (string) $duplicate->roles),
                'reason' => 'One accounting account is assigned to more than one semantic role.',
            ];
        }

        foreach (self::DEFINITIONS as $role => $definition) {
            $definition = $this->normalizedDefinition($definition);
            $account = AccountingAccount::where('code', $definition['code'])->first();
            $mapping = AccountMapping::where('mapped_type', $role)->where('mapped_id', 0)->first();

            if (!$account) {
                $accountsToCreate[] = ['role' => $role] + $definition;
            } elseif (!$this->matchesDefinition($account, $definition)) {
                $conflicts[] = [
                    'kind' => 'incompatible_standard_account',
                    'role' => $role,
                    'accounting_account_id' => $account->id,
                    'code' => $account->code,
                    'reason' => 'The canonical account code exists with incompatible type or flags; it was not changed.',
                ];
            }

            if ($mapping) {
                $mapped = AccountingAccount::find($mapping->accounting_account_id);
                $allowed = AccountingService::ROLE_ACCOUNT_TYPES[$role] ?? [];
                if (!$mapped || !$mapped->is_active || ($allowed && !in_array($mapped->account_type, $allowed, true))) {
                    $conflicts[] = [
                        'kind' => 'invalid_existing_mapping',
                        'role' => $role,
                        'mapping_id' => $mapping->id,
                        'accounting_account_id' => $mapping->accounting_account_id,
                        'reason' => 'The existing customer mapping is missing or incompatible; it was not overwritten.',
                    ];
                } else {
                    $preservedMappings[] = [
                        'role' => $role,
                        'mapping_id' => $mapping->id,
                        'accounting_account_id' => $mapped->id,
                        'code' => $mapped->code,
                        'name' => $mapped->name,
                    ];
                }
                continue;
            }

            $mappingsToCreate[] = [
                'role' => $role,
                'account_code' => $definition['code'],
                'accounting_account_id' => $account?->id,
            ];
        }

        return [
            'schema_issues' => $schemaIssues,
            'accounts_to_create' => $accountsToCreate,
            'mappings_to_create' => $mappingsToCreate,
            'preserved_mappings' => $preservedMappings,
            'conflicts' => $conflicts,
        ];
    }

    public function apply(): array
    {
        $inspection = $this->inspect();
        if ($inspection['schema_issues']) {
            throw new RuntimeException('Required accounting tables are missing: '.implode(', ', $inspection['schema_issues']));
        }
        if ($inspection['conflicts']) {
            throw new RuntimeException('Semantic account upgrade requires manual review; no changes were applied.');
        }

        $changes = DB::transaction(function () use ($inspection) {
            $createdAccounts = [];
            $createdMappings = [];

            foreach ($inspection['accounts_to_create'] as $item) {
                $role = $item['role'];
                unset($item['role']);
                $account = AccountingAccount::firstOrCreate(['code' => $item['code']], $item);
                if ($account->wasRecentlyCreated) {
                    $createdAccounts[] = ['id' => $account->id, 'role' => $role] + $item;
                }
            }

            foreach ($inspection['mappings_to_create'] as $item) {
                if (AccountMapping::where('mapped_type', $item['role'])->where('mapped_id', 0)->exists()) {
                    continue;
                }
                $account = AccountingAccount::where('code', $item['account_code'])->firstOrFail();
                $mapping = AccountMapping::create([
                    'mapped_type' => $item['role'],
                    'mapped_id' => 0,
                    'accounting_account_id' => $account->id,
                ]);
                $createdMappings[] = [
                    'id' => $mapping->id,
                    'role' => $item['role'],
                    'accounting_account_id' => $account->id,
                    'account_code' => $account->code,
                ];
            }

            return compact('createdAccounts', 'createdMappings');
        });

        return $inspection + [
            'created_accounts' => $changes['createdAccounts'],
            'created_mappings' => $changes['createdMappings'],
        ];
    }

    private function normalizedDefinition(array $definition): array
    {
        return $definition + [
            'parent_id' => null,
            'is_control_account' => true,
            'is_system' => true,
            'is_active' => true,
            'is_cash_account' => false,
        ];
    }

    private function matchesDefinition(AccountingAccount $account, array $definition): bool
    {
        return $account->account_type === $definition['account_type']
            && (bool) $account->is_active === (bool) $definition['is_active']
            && (bool) $account->is_system === (bool) $definition['is_system']
            && (bool) $account->is_control_account === (bool) $definition['is_control_account']
            && (bool) $account->is_cash_account === (bool) $definition['is_cash_account'];
    }
}
