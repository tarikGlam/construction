<?php

namespace App\Services;

use App\Models\AccountingConfig;

class AccountingModeService
{
    public function isDoubleEntryAuthoritative(): bool
    {
        $config = AccountingConfig::find(1);
        return (bool) ($config?->enabled && $config?->status === 'active');
    }

    public function isLegacy(): bool
    {
        return !$this->isDoubleEntryAuthoritative();
    }

    public function state(): string
    {
        $config = AccountingConfig::find(1);
        if ($this->isDoubleEntryAuthoritative()) return 'active';
        if (!$config) return 'inactive';
        return ($config->enabled || $config->status === 'active') ? 'needs_attention' : 'inactive';
    }
}
