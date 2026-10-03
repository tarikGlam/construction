<?php

namespace App\Console\Commands;

use App\Services\OutputTaxProvisioningService;
use Illuminate\Console\Command;
use Throwable;

class ProvisionOutputTaxAccounting extends Command
{
    protected $signature = 'accounting:provision-output-tax {--apply : Create the account/mapping and enable the v2 policy when accounting is active}';
    protected $description = 'Inspect or safely provision the Output Tax Payable accounting policy';

    public function handle(OutputTaxProvisioningService $service): int
    {
        try {
            $result = $this->option('apply') ? $service->apply() : ['inspection' => $service->inspect()];
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $ready = (bool) (($result['after']['ready'] ?? null) ?? ($result['inspection']['ready'] ?? false));
            if (!$this->option('apply')) {
                $this->comment('Dry run only; no accounting configuration or financial records were changed.');
            }
            return $ready ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
