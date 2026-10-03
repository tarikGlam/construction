<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('accounting_configs', 'cutover_at')) {
                $table->timestamp('cutover_at')->nullable()->after('start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounting_configs', function (Blueprint $table) {
            if (Schema::hasColumn('accounting_configs', 'cutover_at')) {
                $table->dropColumn('cutover_at');
            }
        });
    }
};
