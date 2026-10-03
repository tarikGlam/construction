<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer deposits are a financial liability. Store the funded and used
     * balances exactly at the same four-decimal scale used by the accounting
     * journal, instead of relying on binary floating-point columns.
     */
    public function up(): void
    {
        // Do not let MySQL silently round legacy DOUBLE values while changing
        // the financial balance columns. An owner-approved remediation must
        // decide how to handle any value outside the four-decimal contract.
        $unsafeRows = DB::table('customers')
            ->whereRaw(
                'ABS(COALESCE(deposit, 0)) >= 10000000000000000'
                .' OR ABS(COALESCE(expense, 0)) >= 10000000000000000'
                .' OR (deposit IS NOT NULL AND ROUND(deposit, 4) <> deposit)'
                .' OR (expense IS NOT NULL AND ROUND(expense, 4) <> expense)'
            )
            ->count();

        if ($unsafeRows > 0) {
            throw new \RuntimeException(
                "Customer deposit migration stopped: {$unsafeRows} customer balance(s) exceed DECIMAL(20,4) precision or range."
            );
        }

        $negativeExpenses = DB::table('customers')->where('expense', '<', 0)->count();
        if ($negativeExpenses > 0) {
            Log::warning('Customer deposit migration preserved negative legacy expense balances for reconciliation.', [
                'count' => $negativeExpenses,
            ]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('deposit', 20, 4)->nullable()->change();
            $table->decimal('expense', 20, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->double('deposit')->nullable()->change();
            $table->double('expense')->nullable()->change();
        });
    }
};
