<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['departments', 'designations', 'holidays'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'warehouse_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->unsignedInteger('warehouse_id')->nullable()->index();
                    $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
                });
            }
        }

        DB::statement('UPDATE departments d JOIN (SELECT department_id, MIN(warehouse_id) AS warehouse_id FROM employees WHERE department_id IS NOT NULL GROUP BY department_id HAVING COUNT(*) = COUNT(warehouse_id) AND COUNT(DISTINCT warehouse_id) = 1) e ON e.department_id = d.id SET d.warehouse_id = e.warehouse_id WHERE d.warehouse_id IS NULL');
        DB::statement('UPDATE designations d JOIN (SELECT designation_id, MIN(warehouse_id) AS warehouse_id FROM employees WHERE designation_id IS NOT NULL GROUP BY designation_id HAVING COUNT(*) = COUNT(warehouse_id) AND COUNT(DISTINCT warehouse_id) = 1) e ON e.designation_id = d.id SET d.warehouse_id = e.warehouse_id WHERE d.warehouse_id IS NULL');
        // Legacy holidays were company-wide. Their NULL warehouse_id is intentionally
        // preserved as the explicit representation of a global holiday.
    }

    public function down(): void
    {
        foreach (['holidays', 'designations', 'departments'] as $tableName) {
            if (Schema::hasColumn($tableName, 'warehouse_id')) {
                $foreignKey = $tableName . '_warehouse_id_foreign';
                $foreignKeyExists = DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                    ->where('TABLE_NAME', $tableName)
                    ->where('CONSTRAINT_NAME', $foreignKey)
                    ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
                    ->exists();
                Schema::table($tableName, function (Blueprint $table) use ($foreignKey, $foreignKeyExists): void {
                    if ($foreignKeyExists) {
                        $table->dropForeign($foreignKey);
                    }
                    $table->dropColumn('warehouse_id');
                });
            }
        }
    }
};
