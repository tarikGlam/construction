<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const CONSTRAINT = 'product_returns_product_sale_id_foreign';

    public function up(): void
    {
        if (!Schema::hasTable('product_returns') || !Schema::hasColumn('product_returns', 'product_sale_id')) {
            throw new RuntimeException('product_returns.product_sale_id must exist before its foreign key is enforced.');
        }

        $dangling = DB::table('product_returns as pr')
            ->leftJoin('product_sales as ps', 'ps.id', '=', 'pr.product_sale_id')
            ->whereNotNull('pr.product_sale_id')
            ->whereNull('ps.id')
            ->exists();

        if ($dangling) {
            throw new RuntimeException(
                'Cannot enforce product return source integrity while dangling product_sale_id values exist; run a separate read-only audit.'
            );
        }

        Schema::table('product_returns', function (Blueprint $table) {
            $table->foreign('product_sale_id', self::CONSTRAINT)
                ->references('id')
                ->on('product_sales')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_returns', function (Blueprint $table) {
            $table->dropForeign(self::CONSTRAINT);
        });
    }
};
