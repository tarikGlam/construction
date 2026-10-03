<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('money_transfers')) {
            Schema::table('money_transfers', function (Blueprint $table) {
                if (!Schema::hasColumn('money_transfers', 'project_id')) $table->unsignedBigInteger('project_id')->nullable()->index();
                if (!Schema::hasColumn('money_transfers', 'site_id')) $table->unsignedBigInteger('site_id')->nullable()->index();
                if (!Schema::hasColumn('money_transfers', 'external_reference')) $table->string('external_reference')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('money_transfers')) {
            Schema::table('money_transfers', function (Blueprint $table) {
                $columns = [];
                foreach (['external_reference','site_id','project_id'] as $column) if (Schema::hasColumn('money_transfers', $column)) $columns[] = $column;
                if ($columns) $table->dropColumn($columns);
            });
        }
    }
};
