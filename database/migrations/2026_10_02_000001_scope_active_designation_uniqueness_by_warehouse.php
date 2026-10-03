<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('designations', function (Blueprint $table): void {
            $table->dropUnique('designations_name_unique');
            $table->unsignedTinyInteger('active_unique')
                ->nullable()
                ->storedAs('IF(`is_active` = 1, 1, NULL)')
                ->after('is_active');
            $table->unique(
                ['name', 'warehouse_id', 'active_unique'],
                'designations_active_name_warehouse_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('designations', function (Blueprint $table): void {
            $table->dropUnique('designations_active_name_warehouse_unique');
            $table->dropColumn('active_unique');
            $table->unique('name', 'designations_name_unique');
        });
    }
};
