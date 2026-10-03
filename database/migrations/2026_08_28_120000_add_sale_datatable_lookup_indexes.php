<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $indexes = \Illuminate\Support\Facades\DB::select("SHOW INDEX FROM `{$table}` WHERE `Key_name` = ?", [$indexName]);
            return !empty($indexes);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!$this->hasIndex('sales', 'sales_deleted_created_at_index')) {
                $table->index(['deleted_at', 'created_at'], 'sales_deleted_created_at_index');
            }
            if (!$this->hasIndex('sales', 'sales_warehouse_deleted_created_index')) {
                $table->index(['warehouse_id', 'deleted_at', 'created_at'], 'sales_warehouse_deleted_created_index');
            }
            if (!$this->hasIndex('sales', 'sales_user_deleted_created_index')) {
                $table->index(['user_id', 'deleted_at', 'created_at'], 'sales_user_deleted_created_index');
            }
        });

        Schema::table('product_sales', function (Blueprint $table) {
            if (!$this->hasIndex('product_sales', 'product_sales_sale_product_index')) {
                $table->index(['sale_id', 'product_id'], 'product_sales_sale_product_index');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (!$this->hasIndex('payments', 'payments_sale_return_method_index')) {
                $table->index(['sale_id', 'return_id', 'paying_method'], 'payments_sale_return_method_index');
            }
            if (!$this->hasIndex('payments', 'payments_return_sale_index')) {
                $table->index(['return_id', 'sale_id'], 'payments_return_sale_index');
            }
        });

        Schema::table('returns', function (Blueprint $table) {
            if (!$this->hasIndex('returns', 'returns_sale_id_index')) {
                $table->index('sale_id', 'returns_sale_id_index');
            }
        });

        Schema::table('deliveries', function (Blueprint $table) {
            if (!$this->hasIndex('deliveries', 'deliveries_sale_id_index')) {
                $table->index('sale_id', 'deliveries_sale_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if ($this->hasIndex('deliveries', 'deliveries_sale_id_index')) {
                $table->dropIndex('deliveries_sale_id_index');
            }
        });

        Schema::table('returns', function (Blueprint $table) {
            if ($this->hasIndex('returns', 'returns_sale_id_index')) {
                $table->dropIndex('returns_sale_id_index');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if ($this->hasIndex('payments', 'payments_sale_return_method_index')) {
                $table->dropIndex('payments_sale_return_method_index');
            }
            if ($this->hasIndex('payments', 'payments_return_sale_index')) {
                $table->dropIndex('payments_return_sale_index');
            }
        });

        Schema::table('product_sales', function (Blueprint $table) {
            if ($this->hasIndex('product_sales', 'product_sales_sale_product_index')) {
                $table->dropIndex('product_sales_sale_product_index');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if ($this->hasIndex('sales', 'sales_deleted_created_at_index')) {
                $table->dropIndex('sales_deleted_created_at_index');
            }
            if ($this->hasIndex('sales', 'sales_warehouse_deleted_created_index')) {
                $table->dropIndex('sales_warehouse_deleted_created_index');
            }
            if ($this->hasIndex('sales', 'sales_user_deleted_created_index')) {
                $table->dropIndex('sales_user_deleted_created_index');
            }
        });
    }
};
