<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable()->index()->after('deleted_by');
            $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            $table->text('void_reason')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['voided_at']);
            $table->dropColumn(['voided_at', 'voided_by', 'void_reason']);
        });
    }
};
