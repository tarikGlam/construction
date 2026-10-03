<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_returns', function (Blueprint $table) {
            $table->unsignedInteger('product_sale_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('product_returns', function (Blueprint $table) {
            $table->dropColumn('product_sale_id');
        });
    }
};
