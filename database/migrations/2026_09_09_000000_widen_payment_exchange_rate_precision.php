<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 20, 8)->default(1)->change();
        });
    }

    public function down(): void
    {
        // Refuse a lossy rollback once precise rates have been recorded.
        if (DB::table('payments')->whereRaw('exchange_rate <> ROUND(exchange_rate, 2) OR ABS(exchange_rate) > 999999.99')->exists()) {
            throw new RuntimeException('Cannot narrow payment exchange rates without losing stored precision.');
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 8, 2)->default(1)->change();
        });
    }
};
