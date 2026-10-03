<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_stock_layers', function (Blueprint $table) {
            if (!Schema::hasColumn('import_stock_layers', 'product_batch_id')) {
                $table->unsignedInteger('product_batch_id')->nullable()->after('variant_id');
            }
            if (!Schema::hasColumn('import_stock_layers', 'goods_base_amount')) {
                $table->decimal('goods_base_amount', 18, 4)->default(0)->after('total_unit_cost');
            }
            if (!Schema::hasColumn('import_stock_layers', 'allocated_landed_amount')) {
                $table->decimal('allocated_landed_amount', 18, 4)->default(0)->after('goods_base_amount');
            }
            if (!Schema::hasColumn('import_stock_layers', 'remaining_goods_amount')) {
                $table->decimal('remaining_goods_amount', 18, 4)->default(0)->after('allocated_landed_amount');
            }
            if (!Schema::hasColumn('import_stock_layers', 'remaining_landed_amount')) {
                $table->decimal('remaining_landed_amount', 18, 4)->default(0)->after('remaining_goods_amount');
            }
        });
    }

    public function down(): void
    {
        // The original create migration now defines these columns for fresh
        // installations, so rolling this compatibility migration back must
        // never remove columns owned by that migration.
    }
};
