<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_points', function (Blueprint $table): void {
            $table->string('event_type', 40)->nullable()->after('reward_point_type');
            $table->string('event_key', 191)->nullable()->unique()->after('event_type');
            $table->unsignedBigInteger('payment_id')->nullable()->index()->after('sale_id');
            $table->unsignedBigInteger('return_id')->nullable()->index()->after('payment_id');
            $table->decimal('source_amount', 20, 4)->nullable()->after('return_id');
            $table->decimal('conversion_rate', 20, 8)->nullable()->after('source_amount');
            $table->decimal('balance_after', 20, 4)->nullable()->after('conversion_rate');
            $table->unsignedBigInteger('reward_point_setting_id')->nullable()->after('balance_after');
        });
    }

    public function down(): void
    {
        Schema::table('reward_points', function (Blueprint $table): void {
            $table->dropUnique(['event_key']);
            $table->dropIndex(['payment_id']);
            $table->dropIndex(['return_id']);
            $table->dropColumn([
                'event_type',
                'event_key',
                'payment_id',
                'return_id',
                'source_amount',
                'conversion_rate',
                'balance_after',
                'reward_point_setting_id',
            ]);
        });
    }
};
