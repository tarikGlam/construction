<?php

return [
    // The browser endpoint launches a cancellable read-only child process. Full
    // release certification remains a CLI responsibility.
    'web_health_timeout_seconds' => 30,
    'mysql_binary' => null,
    'restore_verification_database_prefix' => 'salepro_restore_verify_',

    /*
    | Immediate-release feature profile
    |
    | These capabilities remain in the codebase for controlled development,
    | but must be explicitly enabled. Existing stable Accounting Health and
    | explicitly stored tax-policy behavior are not disabled by these flags.
    */
    'health_advanced_enabled' => (bool) env('ACCOUNTING_HEALTH_ADVANCED_ENABLED', false),
    'deep_scan_enabled' => (bool) env('ACCOUNTING_DEEP_SCAN_ENABLED', false),
    'guided_repair_enabled' => (bool) env('ACCOUNTING_GUIDED_REPAIR_ENABLED', false),
    // Existing businesses retain their stored policy. Fresh accounting
    // activations use the tax split unless an operator explicitly opts out.
    'tax_split_v2_new_activation_enabled' => (bool) env('ACCOUNTING_TAX_SPLIT_V2_NEW_ACTIVATION_ENABLED', true),
];
