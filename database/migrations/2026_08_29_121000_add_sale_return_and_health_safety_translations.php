<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('translations')) {
            return;
        }

        $translations = [
            'sale_return_quantity_positive' => 'Enter a return quantity greater than zero for every item, or remove the empty item row.',
            'sale_return_line_required' => 'Add at least one item with a valid return quantity.',
            'sale_return_line_mismatch' => 'The return item details do not match. Refresh the page and try again.',
            'sale_return_line_invalid' => 'One of the selected items does not belong to this sale. Refresh the page and try again.',
            'sale_return_quantity_exceeds_available' => 'The return quantity is greater than the available quantity (:quantity).',
            'accounting_health_check_failed_safe' => 'The accounting health check could not be completed. No accounting data was changed. An administrator can review the technical log.',
            'accounting_health_prerequisite_missing' => 'The accounting health check requires the following PHP extension(s): :extensions. No accounting data was changed.',
            'accounting_health_bounded_check_output' => 'Bounded read-only web health check completed. Run the full accounting certification from the command line for release-level verification.',
        ];

        foreach ($translations as $key => $value) {
            DB::table('translations')->updateOrInsert(
                ['locale' => 'en', 'group' => 'db', 'key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Retain translations because customers may have customized them after upgrade.
    }
};
