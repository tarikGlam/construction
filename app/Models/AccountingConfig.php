<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingConfig extends Model
{
    protected $fillable = [
        'id',
        'enabled',
        'status',
        'advanced_diagnostics_enabled',
        'deep_scan_enabled',
        'activation_mode',
        'start_date',
        'cutover_at',
        'sales_tax_policy_version',
        'sales_tax_policy_effective_at',
        'opening_journal_entry_id',
        'activated_by',
        'activated_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'advanced_diagnostics_enabled' => 'boolean',
        'deep_scan_enabled' => 'boolean',
        'activated_at' => 'datetime',
        'cutover_at' => 'datetime',
        'sales_tax_policy_effective_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function (AccountingConfig $config) {
            if (!$config->id) {
                $config->id = 1;
            }

            if ((int) $config->id !== 1) {
                throw new \RuntimeException('AccountingConfig is a singleton and must use id 1.');
            }

            if (static::where('id', 1)->exists()) {
                throw new \RuntimeException('AccountingConfig singleton already exists.');
            }
        });
    }
}
