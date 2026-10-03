<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('productions') && !Schema::hasColumn('productions', 'variant_list')) {
            Schema::table('productions', function (Blueprint $table) {
                $table->text('variant_list')->nullable()->after('production_units_ids');
            });
        }
    }

    public function down(): void
    {
        // A client database may have had this column before this migration.
        // Never drop potentially historical variant data during rollback.
    }
};
