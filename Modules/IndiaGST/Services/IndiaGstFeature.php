<?php

namespace Modules\IndiaGST\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\GeneralSetting;

class IndiaGstFeature
{
    /**
     * Resolve and populate the 'india-gst.enabled' config.
     */
    public static function resolve(): bool
    {
        $enabled = false;

        try {
            if (Schema::hasTable('general_settings') && Schema::hasColumn('general_settings', 'is_india_gst_enabled')) {
                // Fetch using query builder to avoid Eloquent overhead and caching issues during tenant switch
                $setting = DB::table('general_settings')->latest('id')->first();
                if ($setting) {
                    $enabled = (bool) $setting->is_india_gst_enabled;
                }
            }
        } catch (\Exception $e) {
            // Ignore during early installation/migrations when tables might not exist
        }

        config(['india-gst.enabled' => $enabled]);

        return $enabled;
    }

    /**
     * Reset config to disabled state (used upon TenancyEnded).
     */
    public static function reset(): void
    {
        config(['india-gst.enabled' => false]);
    }

    /**
     * Helper to assert India GST is disabled and optionally throw if blocked.
     */
    public static function enforceDisabledFallback($message = 'India GST is disabled. Please enable it in General Settings to modify this transaction.'): void
    {
        if (!config('india-gst.enabled', false)) {
            abort(403, $message);
        }
    }
}
