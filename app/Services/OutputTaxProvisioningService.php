<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingConfig;
use App\Models\AccountMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OutputTaxProvisioningService
{
    public const CODE = '2200';

    public function inspect(): array
    {
        $account = AccountingAccount::where('code', self::CODE)->first();
        $mappings = AccountMapping::where('mapped_type', AccountingService::ROLE_OUTPUT_TAX_PAYABLE)->where('mapped_id', 0)->get();
        $mapping = $mappings->first();
        $mapped = $mapping ? AccountingAccount::find($mapping->accounting_account_id) : null;
        $conflicts = [];
        if ($account && ($account->account_type !== 'liability' || !$account->is_active)) $conflicts[] = 'code_conflict';
        if ($account && strcasecmp(trim((string) $account->name), 'Output Tax Payable') !== 0) $conflicts[] = 'account_name_conflict';
        if ($mappings->count() > 1) $conflicts[] = 'duplicate_mapping';
        if ($mapping && (!$mapped || $mapped->account_type !== 'liability' || !$mapped->is_active)) $conflicts[] = 'invalid_mapping';
        if ($mapping && (!$account || (int) $mapping->accounting_account_id !== (int) $account->id)) $conflicts[] = 'mapping_code_conflict';
        return ['ready' => (bool) $account && (bool) $mapping && !$conflicts, 'account_exists' => (bool) $account,
            'mapping_exists' => (bool) $mapping, 'account_id' => $account?->id, 'mapped_account_id' => $mapping?->accounting_account_id,
            'mapping_count' => $mappings->count(), 'conflicts' => array_values(array_unique($conflicts))];
    }

    public function apply(): array
    {
        if (!config('accounting.tax_split_v2_new_activation_enabled', false)) {
            throw new RuntimeException(
                'Output Tax v2 activation is disabled for this release. Use only a controlled development/testing environment with the explicit feature gate enabled.'
            );
        }

        return DB::transaction(function () {
            $before = $this->inspect();
            if ($before['conflicts']) throw new RuntimeException('Output Tax Payable provisioning requires manual review.');
            $account = AccountingAccount::firstOrCreate(['code' => self::CODE], ['name' => 'Output Tax Payable',
                'account_type' => 'liability', 'parent_id' => null, 'is_control_account' => true, 'is_system' => true,
                'is_active' => true, 'is_cash_account' => false]);
            AccountMapping::firstOrCreate(['mapped_type' => AccountingService::ROLE_OUTPUT_TAX_PAYABLE, 'mapped_id' => 0],
                ['accounting_account_id' => $account->id]);
            $config = AccountingConfig::whereKey(1)->lockForUpdate()->first();
            if ($config?->enabled) $config->update(['sales_tax_policy_version' => TaxAccountingComponentService::POLICY_V2,
                'sales_tax_policy_effective_at' => $config->sales_tax_policy_effective_at ?: now()]);
            return ['before' => $before, 'after' => $this->inspect(), 'policy_enabled' => (bool) $config?->enabled];
        });
    }
}
