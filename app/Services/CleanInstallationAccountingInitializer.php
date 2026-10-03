<?php

namespace App\Services;

use App\Models\AccountingConfig;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Runs only from a proven fresh-install/provisioning context. */
class CleanInstallationAccountingInitializer
{
    public function initialize(): void
    {
        $config = AccountingConfig::find(1);
        if ($config?->enabled && $config->status === 'active') return;

        if ($config && ($config->enabled || $config->status === 'active')) {
            throw new RuntimeException('Accounting setup is incomplete and requires administrator review.');
        }

        if (app(AccountingActivationService::class)->requiresExistingBusinessMode()) {
            throw new RuntimeException('A clean installation cannot contain operational history before accounting setup.');
        }

        DB::transaction(function () {
            $service = app(AccountingActivationService::class);
            $current = $service->getConfig();
            if (!($current->enabled && $current->status === 'active')) {
                $service->activate('new_business');
            }
        }, 3);
    }
}
