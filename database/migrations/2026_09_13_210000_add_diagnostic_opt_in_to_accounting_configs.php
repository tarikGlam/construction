<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['name' => 'accounting-health-settings-manage', 'guard_name' => 'web'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        Schema::table('accounting_configs', function (Blueprint $table) {
            $table->boolean('advanced_diagnostics_enabled')->default(false)->after('status');
            $table->boolean('deep_scan_enabled')->default(false)->after('advanced_diagnostics_enabled');
        });

        Schema::create('accounting_health_setting_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('before_state');
            $table->json('after_state');
            $table->string('previous_event_hash', 64)->nullable();
            $table->string('event_hash', 64);
            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'accounting-health-settings-manage')
            ->where('guard_name', 'web')
            ->value('id');
        if ($permissionId && (DB::table('role_has_permissions')->where('permission_id', $permissionId)->exists()
            || DB::table('model_has_permissions')->where('permission_id', $permissionId)->exists())) {
            throw new RuntimeException('The Accounting Health settings permission is assigned and cannot be removed safely.');
        }
        if ($permissionId) {
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::dropIfExists('accounting_health_setting_audits');
        Schema::table('accounting_configs', function (Blueprint $table) {
            $table->dropColumn(['advanced_diagnostics_enabled', 'deep_scan_enabled']);
        });
    }
};
