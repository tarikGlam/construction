<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales', 'sales_agent_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedInteger('sales_agent_id')->nullable()->after('user_id');
                $table->index(['sales_agent_id', 'created_at'], 'sales_agent_created_index');
                $table->foreign('sales_agent_id')->references('id')->on('employees')->nullOnDelete();
            });

            // Preserve the previous SalePro commission semantics only where the
            // login-to-employee link is unambiguous. New sales use the explicit
            // employee assignment and never infer the agent from user_id.
            DB::statement(<<<'SQL'
                UPDATE sales AS s
                INNER JOIN (
                    SELECT user_id, MIN(id) AS employee_id
                    FROM employees
                    WHERE user_id IS NOT NULL
                      AND is_active = 1
                      AND is_sale_agent = 1
                    GROUP BY user_id
                    HAVING COUNT(*) = 1
                ) AS agent ON agent.user_id = s.user_id
                SET s.sales_agent_id = agent.employee_id
                WHERE s.sales_agent_id IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'sales_agent_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropForeign(['sales_agent_id']);
                $table->dropIndex('sales_agent_created_index');
                $table->dropColumn('sales_agent_id');
            });
        }
    }
};
