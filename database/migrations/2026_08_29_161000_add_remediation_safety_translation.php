<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('translations')) {
            return;
        }

        DB::table('translations')->updateOrInsert(
            ['locale' => 'en', 'group' => 'db', 'key' => 'accounting_health_payment_mapping_command_required'],
            [
                'value' => 'This browser screen is review-only. An administrator must use the protected command with a restore-verified backup to apply changes.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        // Retain translations because customers may customize them after upgrade.
    }
};
