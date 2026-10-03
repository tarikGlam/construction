<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('project_wages')) {
            Schema::table('project_wages', function (Blueprint $table) {
                if (!Schema::hasColumn('project_wages', 'rate')) $table->decimal('rate', 20, 4)->nullable()->after('days_worked');
                if (!Schema::hasColumn('project_wages', 'rate_basis')) $table->string('rate_basis', 20)->nullable()->after('rate');
            });
        }

        if (!Schema::hasTable('construction_employee_rewards')) {
            Schema::create('construction_employee_rewards', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('employee_id')->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->date('reward_date')->index();
                $table->string('reward_type', 40)->default('bonus');
                $table->decimal('amount', 20, 4);
                $table->string('status', 20)->default('approved')->index();
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('construction_employee_expenses')) {
            Schema::create('construction_employee_expenses', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('employee_id')->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedBigInteger('cost_category_id')->index();
                $table->unsignedInteger('expense_id')->nullable()->unique();
                $table->unsignedInteger('warehouse_id')->index();
                $table->unsignedInteger('account_id')->index();
                $table->date('expense_date')->index();
                $table->decimal('amount', 20, 4);
                $table->string('purpose');
                $table->string('reference')->nullable();
                $table->string('posting_status', 30)->default('operational')->index();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('construction_employee_advances')) {
            Schema::create('construction_employee_advances', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('employee_id')->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedInteger('expense_id')->nullable()->unique();
                $table->unsignedInteger('warehouse_id')->index();
                $table->unsignedInteger('account_id')->index();
                $table->date('advance_date')->index();
                $table->decimal('amount', 20, 4);
                $table->decimal('settled_expense_amount', 20, 4)->default(0);
                $table->decimal('cash_returned_amount', 20, 4)->default(0);
                $table->decimal('outstanding_amount', 20, 4);
                $table->string('status', 20)->default('open')->index();
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->string('posting_status', 30)->default('operational')->index();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('construction_employee_advance_settlements')) {
            Schema::create('construction_employee_advance_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_advance_id');
                $table->index('employee_advance_id', 'ceas_advance_idx');
                $table->unsignedBigInteger('project_id')->nullable();
                $table->index('project_id', 'ceas_project_idx');
                $table->unsignedBigInteger('site_id')->nullable();
                $table->index('site_id', 'ceas_site_idx');
                $table->date('settlement_date');
                $table->index('settlement_date', 'ceas_date_idx');
                $table->string('settlement_type', 30);
                $table->index('settlement_type', 'ceas_type_idx');
                $table->decimal('amount', 20, 4);
                $table->unsignedBigInteger('cost_category_id')->nullable();
                $table->index('cost_category_id', 'ceas_cost_category_idx');
                $table->unsignedInteger('account_id')->nullable();
                $table->index('account_id', 'ceas_account_idx');
                $table->unsignedInteger('expense_id')->nullable();
                $table->unique('expense_id', 'ceas_expense_unique');
                $table->unsignedBigInteger('journal_entry_id')->nullable();
                $table->index('journal_entry_id', 'ceas_journal_idx');
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->index('created_by', 'ceas_created_by_idx');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_employee_advance_settlements');
        Schema::dropIfExists('construction_employee_advances');
        Schema::dropIfExists('construction_employee_expenses');
        Schema::dropIfExists('construction_employee_rewards');
        if (Schema::hasTable('project_wages')) {
            Schema::table('project_wages', function (Blueprint $table) {
                foreach (['rate_basis', 'rate'] as $column) if (Schema::hasColumn('project_wages', $column)) $table->dropColumn($column);
            });
        }
    }
};
