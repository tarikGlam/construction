<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'project_code')) $table->string('project_code', 50)->nullable()->unique();
            if (!Schema::hasColumn('projects', 'location')) $table->string('location')->nullable();
            if (!Schema::hasColumn('projects', 'project_manager_id')) $table->unsignedInteger('project_manager_id')->nullable()->index();
            if (!Schema::hasColumn('projects', 'contract_value')) $table->decimal('contract_value', 20, 4)->default(0);
            if (!Schema::hasColumn('projects', 'budget')) $table->decimal('budget', 20, 4)->default(0);
            if (!Schema::hasColumn('projects', 'expected_end_date')) $table->date('expected_end_date')->nullable();
            if (!Schema::hasColumn('projects', 'actual_end_date')) $table->date('actual_end_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn([
            'project_code', 'location', 'project_manager_id', 'contract_value', 'budget', 'expected_end_date', 'actual_end_date'
        ]));
    }
};
