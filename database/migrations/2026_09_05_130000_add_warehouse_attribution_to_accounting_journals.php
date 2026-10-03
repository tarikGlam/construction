<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedInteger('warehouse_id')->nullable()->after('source_id')->index();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->unsignedInteger('warehouse_id')->nullable()->after('user_id')->index();
            $table->uuid('request_token')->nullable()->after('warehouse_id')->unique();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropIndex(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropIndex(['warehouse_id']);
            $table->dropUnique(['request_token']);
            $table->dropColumn('warehouse_id');
            $table->dropColumn('request_token');
        });
    }
};
