<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('purchases', 'import_batch_id')) {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('warehouse_id');
                $table->index('import_batch_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'import_batch_id')) {
                $table->dropIndex(['import_batch_id']);
                $table->dropColumn('import_batch_id');
            }
        });
    }
};
