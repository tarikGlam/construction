<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('accounting_remediation_runs')
            || Schema::hasColumn('accounting_remediation_runs', 'manifest_payload_json')) {
            return;
        }

        Schema::table('accounting_remediation_runs', function (Blueprint $table) {
            $table->longText('manifest_payload_json')->nullable()->after('result_json');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('accounting_remediation_runs')
            || !Schema::hasColumn('accounting_remediation_runs', 'manifest_payload_json')) {
            return;
        }

        Schema::table('accounting_remediation_runs', function (Blueprint $table) {
            $table->dropColumn('manifest_payload_json');
        });
    }
};
