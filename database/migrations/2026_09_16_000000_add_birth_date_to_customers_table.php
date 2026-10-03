<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'birth_date')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->date('birth_date')->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'birth_date')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('birth_date');
            });
        }
    }
};
