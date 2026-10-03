<?php

namespace App\Services;

use App\Models\AccountingConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class DeepScanReadinessService
{
    public const ADVANCED_CONFIG_KEY = 'ACCOUNTING_HEALTH_ADVANCED_ENABLED';
    public const DEEP_SCAN_CONFIG_KEY = 'ACCOUNTING_DEEP_SCAN_ENABLED';
    public const QUEUE_CONFIG_KEY = 'QUEUE_CONNECTION';
    public const WORKER_HEARTBEAT_CACHE_KEY = 'accounting_health.deep_scan_worker_seen_at';
    public const SUPPORTED_QUEUES = ['database', 'redis', 'sqs', 'beanstalkd'];

    public function assess(): array
    {
        $installationAdvanced = (bool) config('accounting.health_advanced_enabled', false);
        $installationDeep = (bool) config('accounting.deep_scan_enabled', false);
        $configSchemaReady = Schema::hasTable('accounting_configs')
            && Schema::hasColumns('accounting_configs', ['advanced_diagnostics_enabled', 'deep_scan_enabled']);
        $scanSchemaReady = Schema::hasTable('accounting_diagnostic_scans')
            && Schema::hasColumns('accounting_diagnostic_scans', ['scan_key', 'status', 'progress', 'checkpoints', 'dataset_as_of']);
        $business = $configSchemaReady ? AccountingConfig::find(1) : null;
        $queue = (string) config('queue.default', 'sync');
        $queueConfigured = in_array($queue, self::SUPPORTED_QUEUES, true);
        $workerSeenAt = Cache::get(self::WORKER_HEARTBEAT_CACHE_KEY);
        $workerReady = false;
        if ($queueConfigured && is_string($workerSeenAt)) {
            try {
                $workerReady = CarbonImmutable::parse($workerSeenAt)->greaterThan(now()->subMinutes(5));
            } catch (\Throwable) {
                $workerReady = false;
            }
        }
        $user = Auth::user();
        $permissionReady = (bool) ($user && ((int) $user->role_id <= 2 || $user->hasPermissionTo('accounting-health-scan-launch')));
        $settingsPermission = (bool) ($user && ((int) $user->role_id <= 2 || $user->hasPermissionTo('accounting-health-settings-manage')));
        $accountingReady = (bool) ($business?->enabled && $business?->status === 'active' && $business?->cutover_at);
        $advancedEnabled = (bool) $business?->advanced_diagnostics_enabled;
        $deepEnabled = (bool) $business?->deep_scan_enabled;

        $reasons = [];
        if (!$installationAdvanced || !$installationDeep) $reasons[] = __('db.accounting_health_deep_reason_installation_unavailable');
        if (!$configSchemaReady || !$scanSchemaReady) $reasons[] = __('db.accounting_health_deep_reason_schema_missing');
        if (!$advancedEnabled) $reasons[] = __('db.accounting_health_deep_reason_business_advanced_disabled');
        if (!$deepEnabled) $reasons[] = __('db.accounting_health_deep_reason_business_scan_disabled');
        if (!$accountingReady) $reasons[] = __('db.accounting_health_deep_reason_accounting_not_ready');
        if (!$queueConfigured) $reasons[] = __('db.accounting_health_deep_reason_queue', ['connection' => $queue]);
        elseif (!$workerReady) $reasons[] = __('db.accounting_health_deep_reason_worker_unavailable');
        if (!$permissionReady) $reasons[] = __('db.accounting_health_deep_reason_permission_missing');

        return [
            'installation_advanced' => $installationAdvanced,
            'installation_deep_scan' => $installationDeep,
            'installation_available' => $installationAdvanced && $installationDeep,
            'advanced_enabled' => $advancedEnabled,
            'feature_enabled' => $deepEnabled,
            'accounting_ready' => $accountingReady,
            'permission_ready' => $permissionReady,
            'settings_permission' => $settingsPermission,
            'config_schema_ready' => $configSchemaReady,
            'schema_ready' => $configSchemaReady && $scanSchemaReady,
            'queue_configured' => $queueConfigured,
            'queue_ready' => $queueConfigured && $workerReady,
            'worker_ready' => $workerReady,
            'worker_seen_at' => $workerReady ? $workerSeenAt : null,
            'queue_connection' => $queue,
            'supported_queues' => self::SUPPORTED_QUEUES,
            'available' => $installationAdvanced && $installationDeep && $configSchemaReady && $scanSchemaReady
                && $advancedEnabled && $deepEnabled && $accountingReady && $queueConfigured && $workerReady && $permissionReady,
            'reasons' => $reasons,
        ];
    }
}
