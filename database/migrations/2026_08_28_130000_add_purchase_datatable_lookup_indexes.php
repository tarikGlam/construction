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
        Schema::table('purchases', function (Blueprint $table) {
            if (!$this->hasIndex('purchases', 'purchases_deleted_created_index')) {
                $table->index(['deleted_at', 'created_at'], 'purchases_deleted_created_index');
            }
            if (!$this->hasIndex('purchases', 'purchases_warehouse_deleted_created_index')) {
                $table->index(['warehouse_id', 'deleted_at', 'created_at'], 'purchases_warehouse_deleted_created_index');
            }
            if (!$this->hasIndex('purchases', 'purchases_user_deleted_created_index')) {
                $table->index(['user_id', 'deleted_at', 'created_at'], 'purchases_user_deleted_created_index');
            }
        });

        Schema::table('product_purchases', function (Blueprint $table) {
            if (!$this->hasIndex('product_purchases', 'product_purchases_purchase_product_index')) {
                $table->index(['purchase_id', 'product_id'], 'product_purchases_purchase_product_index');
            }
        });

        Schema::table('return_purchases', function (Blueprint $table) {
            if (!$this->hasIndex('return_purchases', 'return_purchases_purchase_id_index')) {
                $table->index('purchase_id', 'return_purchases_purchase_id_index');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (!$this->hasIndex('payments', 'payments_purchase_return_id_index')) {
                $table->index('purchase_return_id', 'payments_purchase_return_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if ($this->hasIndex('payments', 'payments_purchase_return_id_index')) {
                $table->dropIndex('payments_purchase_return_id_index');
            }
        });

        Schema::table('return_purchases', function (Blueprint $table) {
            if ($this->hasIndex('return_purchases', 'return_purchases_purchase_id_index')) {
                $table->dropIndex('return_purchases_purchase_id_index');
            }
        });

        Schema::table('product_purchases', function (Blueprint $table) {
            if ($this->hasIndex('product_purchases', 'product_purchases_purchase_product_index')) {
                $table->dropIndex('product_purchases_purchase_product_index');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if ($this->hasIndex('purchases', 'purchases_user_deleted_created_index')) {
                $table->dropIndex('purchases_user_deleted_created_index');
            }
            if ($this->hasIndex('purchases', 'purchases_warehouse_deleted_created_index')) {
                $table->dropIndex('purchases_warehouse_deleted_created_index');
            }
            if ($this->hasIndex('purchases', 'purchases_deleted_created_index')) {
                $table->dropIndex('purchases_deleted_created_index');
            }
        });
    }
};
