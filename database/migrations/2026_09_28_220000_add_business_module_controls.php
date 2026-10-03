<?php

use App\Services\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('general_settings') && !Schema::hasColumn('general_settings', 'disabled_modules')) {
            Schema::table('general_settings', function (Blueprint $table) {
                $table->text('disabled_modules')->nullable()->after('modules');
            });
        }

        // A NULL value means module controls have never been initialized. The
        // disabled-list model makes an empty string the canonical "all available
        // modules enabled" state. Once any value exists, leave it untouched so a
        // customer's explicit OFF choices survive later upgrades.
        if (Schema::hasTable('general_settings') && Schema::hasColumn('general_settings', 'disabled_modules')) {
            $settings = DB::table('general_settings')->latest('id')->first();
            if ($settings && $settings->disabled_modules === null) {
                DB::table('general_settings')->where('id', $settings->id)->update(['disabled_modules' => '']);
            }
        }

        foreach (ModuleRegistry::accessPermissions() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Existing roles retain Repair access if they already had any Repair permission.
        $repairAccessId = DB::table('permissions')->where('name', 'module.repair.access')->value('id');
        $legacyRepairIds = DB::table('permissions')->whereIn('name', ModuleRegistry::permissionNames('repair', false))->pluck('id');
        $roleIds = DB::table('role_has_permissions')->whereIn('permission_id', $legacyRepairIds)->pluck('role_id')->unique();
        $roleIds = $roleIds->merge(DB::table('roles')->whereIn('id', [1, 2])->pluck('id'))->unique();
        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $repairAccessId]);
        }
        app('cache')->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        throw new RuntimeException('Business module controls require an explicit downgrade review.');
    }
};
