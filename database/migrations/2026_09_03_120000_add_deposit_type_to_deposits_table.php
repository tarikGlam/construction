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
        if (Schema::hasTable('deposits') && !Schema::hasColumn('deposits', 'deposit_type')) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->string('deposit_type', 32)->nullable()->after('amount');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('deposits') && Schema::hasColumn('deposits', 'deposit_type')) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->dropColumn('deposit_type');
            });
        }
    }
};
