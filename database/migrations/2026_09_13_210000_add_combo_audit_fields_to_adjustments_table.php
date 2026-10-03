<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->unique()->after('reference_no');
            $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->json('composition_snapshot')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_fingerprint', 'composition_snapshot']);
        });
    }
};
