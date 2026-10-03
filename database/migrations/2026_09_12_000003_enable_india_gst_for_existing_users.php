<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Must be non-destructive and schema-aware.
        if (Schema::hasTable('general_settings') && Schema::hasColumn('general_settings', 'is_india_gst_enabled')) {
            $setting = DB::table('general_settings')->latest('id')->first();

            if ($setting && !$setting->is_india_gst_enabled) {
                $shouldEnable = false;

                // Indicator 1: Invoice format is set to gst
                if ($setting->invoice_format === 'gst') {
                    $shouldEnable = true;
                }

                // Indicator 2: Has GST registrations
                if (!$shouldEnable && Schema::hasTable('india_gst_registrations') && DB::table('india_gst_registrations')->exists()) {
                    $shouldEnable = true;
                }

                // Indicator 3: Has historical GST sale snapshots
                if (!$shouldEnable && Schema::hasTable('india_gst_sale_snapshots') && DB::table('india_gst_sale_snapshots')->exists()) {
                    $shouldEnable = true;
                }

                if ($shouldEnable) {
                    DB::table('general_settings')->where('id', $setting->id)->update([
                        'is_india_gst_enabled' => 1
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank to avoid disabling GST unpredictably on rollback
    }
};
