<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cost_categories') && !Schema::hasColumn('cost_categories', 'expense_category_id')) {
            Schema::table('cost_categories', function (Blueprint $table) {
                $table->unsignedInteger('expense_category_id')->nullable()->after('description')->index();
            });
        }

        if (Schema::hasTable('project_costs')) {
            Schema::table('project_costs', function (Blueprint $table) {
                if (!Schema::hasColumn('project_costs', 'warehouse_id')) {
                    $table->unsignedInteger('warehouse_id')->nullable()->after('supplier_id')->index();
                }
                if (!Schema::hasColumn('project_costs', 'account_id')) {
                    $table->unsignedInteger('account_id')->nullable()->after('warehouse_id')->index();
                }
                if (!Schema::hasColumn('project_costs', 'posting_status')) {
                    $table->string('posting_status', 30)->default('operational')->after('attachment')->index();
                }
            });
        }

        if (Schema::hasTable('project_receipts')) {
            Schema::table('project_receipts', function (Blueprint $table) {
                if (!Schema::hasColumn('project_receipts', 'warehouse_id')) {
                    $table->unsignedInteger('warehouse_id')->nullable()->after('customer_id')->index();
                }
                if (!Schema::hasColumn('project_receipts', 'deposit_id')) {
                    $table->unsignedInteger('deposit_id')->nullable()->after('account_id')->index();
                }
            });
        }

        if (Schema::hasTable('journal_lines')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                if (!Schema::hasColumn('journal_lines', 'project_id')) {
                    $table->unsignedBigInteger('project_id')->nullable()->after('accounting_account_id')->index();
                }
                if (!Schema::hasColumn('journal_lines', 'site_id')) {
                    $table->unsignedBigInteger('site_id')->nullable()->after('project_id')->index();
                }
                if (!Schema::hasColumn('journal_lines', 'cost_category_id')) {
                    $table->unsignedBigInteger('cost_category_id')->nullable()->after('site_id')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_lines')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $columns = [];
                if (Schema::hasColumn('journal_lines', 'cost_category_id')) $columns[] = 'cost_category_id';
                if (Schema::hasColumn('journal_lines', 'site_id')) $columns[] = 'site_id';
                if (Schema::hasColumn('journal_lines', 'project_id')) $columns[] = 'project_id';
                if ($columns) $table->dropColumn($columns);
            });
        }

        if (Schema::hasTable('project_receipts')) {
            Schema::table('project_receipts', function (Blueprint $table) {
                $columns = [];
                if (Schema::hasColumn('project_receipts', 'deposit_id')) $columns[] = 'deposit_id';
                if (Schema::hasColumn('project_receipts', 'warehouse_id')) $columns[] = 'warehouse_id';
                if ($columns) $table->dropColumn($columns);
            });
        }

        if (Schema::hasTable('project_costs')) {
            Schema::table('project_costs', function (Blueprint $table) {
                $columns = [];
                if (Schema::hasColumn('project_costs', 'posting_status')) $columns[] = 'posting_status';
                if (Schema::hasColumn('project_costs', 'account_id')) $columns[] = 'account_id';
                if (Schema::hasColumn('project_costs', 'warehouse_id')) $columns[] = 'warehouse_id';
                if ($columns) $table->dropColumn($columns);
            });
        }

        if (Schema::hasTable('cost_categories') && Schema::hasColumn('cost_categories', 'expense_category_id')) {
            Schema::table('cost_categories', function (Blueprint $table) {
                $table->dropColumn('expense_category_id');
            });
        }
    }
};
