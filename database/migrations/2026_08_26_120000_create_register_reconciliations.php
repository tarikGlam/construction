<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_registers')) {
            Schema::table('cash_registers', function (Blueprint $table) {
                if (!Schema::hasColumn('cash_registers', 'closed_by_user_id')) {
                    $table->unsignedInteger('closed_by_user_id')->nullable()->after('user_id');
                }
                if (!Schema::hasColumn('cash_registers', 'closed_at')) {
                    $table->timestamp('closed_at')->nullable()->after('status');
                }
                if (!Schema::hasColumn('cash_registers', 'closing_note')) {
                    $table->text('closing_note')->nullable()->after('closed_at');
                }
            });
        }

        if (!Schema::hasTable('register_reconciliations')) {
            Schema::create('register_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('cash_register_id')->unique();
                $table->unsignedInteger('warehouse_id');
                $table->unsignedInteger('opened_by_user_id');
                $table->unsignedInteger('closed_by_user_id')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->decimal('opening_cash', 19, 4)->default(0);
                $table->decimal('expected_total', 19, 4)->default(0);
                $table->decimal('counted_total', 19, 4)->default(0);
                $table->decimal('variance_total', 19, 4)->default(0);
                $table->text('closing_note')->nullable();
                $table->timestamps();

                $table->index(['warehouse_id', 'closed_at']);
                $table->index(['opened_by_user_id', 'closed_at']);
            });
        }

        if (!Schema::hasTable('register_reconciliation_tenders')) {
            Schema::create('register_reconciliation_tenders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('register_reconciliation_id');
                $table->string('method_key', 191);
                $table->string('method_label', 191);
                $table->decimal('expected_amount', 19, 4)->default(0);
                $table->decimal('counted_amount', 19, 4)->default(0);
                $table->decimal('variance_amount', 19, 4)->default(0);
                $table->text('variance_reason')->nullable();
                $table->timestamps();

                $table->unique(['register_reconciliation_id', 'method_key'], 'register_reconciliation_tender_unique');
                $table->foreign('register_reconciliation_id', 'register_reconciliation_tender_fk')
                    ->references('id')->on('register_reconciliations')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'cash_register_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->index('cash_register_id', 'payments_cash_register_id_index');
            });
        }
        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'cash_register_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->index('cash_register_id', 'expenses_cash_register_id_index');
            });
        }
        if (Schema::hasTable('incomes') && Schema::hasColumn('incomes', 'cash_register_id')) {
            Schema::table('incomes', function (Blueprint $table) {
                $table->index('cash_register_id', 'incomes_cash_register_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('incomes')) {
            Schema::table('incomes', function (Blueprint $table) {
                $table->dropIndex('incomes_cash_register_id_index');
            });
        }
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropIndex('expenses_cash_register_id_index');
            });
        }
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_cash_register_id_index');
            });
        }
        Schema::dropIfExists('register_reconciliation_tenders');
        Schema::dropIfExists('register_reconciliations');
        if (Schema::hasTable('cash_registers')) {
            Schema::table('cash_registers', function (Blueprint $table) {
                $colsToDrop = [];
                if (Schema::hasColumn('cash_registers', 'closed_by_user_id')) $colsToDrop[] = 'closed_by_user_id';
                if (Schema::hasColumn('cash_registers', 'closed_at')) $colsToDrop[] = 'closed_at';
                if (Schema::hasColumn('cash_registers', 'closing_note')) $colsToDrop[] = 'closing_note';
                if (!empty($colsToDrop)) {
                    $table->dropColumn($colsToDrop);
                }
            });
        }
    }
};
