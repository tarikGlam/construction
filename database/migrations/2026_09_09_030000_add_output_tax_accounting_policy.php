<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('accounting_policy_version', 32)->nullable()->after('event_type')->index();
        });
        Schema::table('accounting_configs', function (Blueprint $table) {
            $table->string('sales_tax_policy_version', 32)->nullable()->after('cutover_at');
            $table->timestamp('sales_tax_policy_effective_at')->nullable()->after('sales_tax_policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropColumn('accounting_policy_version'));
        Schema::table('accounting_configs', fn (Blueprint $table) => $table->dropColumn(['sales_tax_policy_version', 'sales_tax_policy_effective_at']));
    }
};
