<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('material_issue_items', function (Blueprint $table) {
            if (!Schema::hasColumn('material_issue_items', 'product_warehouse_id')) $table->unsignedInteger('product_warehouse_id')->nullable()->index();
        });
        Schema::table('material_issues', function (Blueprint $table) {
            if (!Schema::hasColumn('material_issues', 'requested_at')) $table->timestamp('requested_at')->nullable();
            if (!Schema::hasColumn('material_issues', 'approved_at')) $table->timestamp('approved_at')->nullable();
            if (!Schema::hasColumn('material_issues', 'rejected_at')) $table->timestamp('rejected_at')->nullable();
            if (!Schema::hasColumn('material_issues', 'rejection_reason')) $table->text('rejection_reason')->nullable();
        });
        Schema::table('equipment', function (Blueprint $table) {
            if (!Schema::hasColumn('equipment', 'type')) $table->string('type', 100)->nullable()->after('name');
            if (!Schema::hasColumn('equipment', 'serial_no')) $table->string('serial_no', 100)->nullable()->after('type');
            if (!Schema::hasColumn('equipment', 'depreciation_method')) $table->string('depreciation_method', 30)->nullable();
            if (!Schema::hasColumn('equipment', 'useful_life_months')) $table->unsignedInteger('useful_life_months')->nullable();
            if (!Schema::hasColumn('equipment', 'salvage_value')) $table->decimal('salvage_value', 20, 4)->nullable();
            if (!Schema::hasColumn('equipment', 'fixed_asset_id')) $table->unsignedBigInteger('fixed_asset_id')->nullable()->index();
        });
        Schema::create('construction_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 50)->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->date('acquisition_date')->nullable();
            $table->decimal('acquisition_value', 20, 4)->default(0);
            $table->string('depreciation_method', 30)->nullable();
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->decimal('salvage_value', 20, 4)->default(0);
            $table->string('status', 30)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_fixed_assets');
        Schema::table('equipment', function (Blueprint $table) {
            foreach (['type','serial_no','depreciation_method','useful_life_months','salvage_value','fixed_asset_id'] as $column) if (Schema::hasColumn('equipment', $column)) $table->dropColumn($column);
        });
        Schema::table('material_issues', function (Blueprint $table) {
            foreach (['requested_at','approved_at','rejected_at','rejection_reason'] as $column) if (Schema::hasColumn('material_issues', $column)) $table->dropColumn($column);
        });
        Schema::table('material_issue_items', function (Blueprint $table) {
            if (Schema::hasColumn('material_issue_items', 'product_warehouse_id')) $table->dropColumn('product_warehouse_id');
        });
    }
};
