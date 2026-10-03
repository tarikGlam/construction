<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (!Schema::hasColumn('sales', 'idempotency_key')) {
                    $table->string('idempotency_key', 64)->nullable()->unique()->after('reference_no');
                }
                if (!Schema::hasColumn('sales', 'idempotency_fingerprint')) {
                    $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (Schema::hasColumn('sales', 'idempotency_key')) {
                    $table->dropUnique(['idempotency_key']);
                    $table->dropColumn('idempotency_key');
                }
                if (Schema::hasColumn('sales', 'idempotency_fingerprint')) {
                    $table->dropColumn('idempotency_fingerprint');
                }
            });
        }
    }
};
