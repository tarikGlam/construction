<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const PERMISSIONS = [
        'accounting-health-view', 'accounting-health-technical-view', 'accounting-health-scan-launch',
        'accounting-health-scan-control', 'accounting-health-repair-preview', 'accounting-health-repair-approve',
        'accounting-health-repair-execute', 'accounting-health-repair-audit',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('id');
        if (DB::table('role_has_permissions')->whereIn('permission_id', $ids)->exists()
            || DB::table('model_has_permissions')->whereIn('permission_id', $ids)->exists()) {
            throw new RuntimeException('F-012 permissions are assigned; rollback would silently remove authorization policy.');
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
