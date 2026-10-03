<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payments', 'pending_collection_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedInteger('pending_collection_id')->nullable()->after('sale_id');
            });
        }

        if (!$this->hasIndex('payments_pending_collection_id_unique')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->unique('pending_collection_id', 'payments_pending_collection_id_unique');
            });
        }

        if (!$this->hasForeignKey('payments_pending_collection_id_foreign')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('pending_collection_id', 'payments_pending_collection_id_foreign')
                    ->references('id')->on('pending_collections')->onDelete('restrict');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('payments', 'pending_collection_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if ($this->hasForeignKey('payments_pending_collection_id_foreign')) {
                $table->dropForeign('payments_pending_collection_id_foreign');
            }
            if ($this->hasIndex('payments_pending_collection_id_unique')) {
                $table->dropUnique('payments_pending_collection_id_unique');
            }
            $table->dropColumn('pending_collection_id');
        });
    }

    private function hasIndex(string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'payments')
            ->where('INDEX_NAME', $name)
            ->exists();
    }

    private function hasForeignKey(string $name): bool
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'payments')
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }
};
