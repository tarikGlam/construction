<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouses') && ! Schema::hasColumn('warehouses', 'is_restaurant')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->boolean('is_restaurant')->default(false)->after('address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'is_restaurant')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropColumn('is_restaurant');
            });
        }
    }
};
