<?php

use App\Models\Translation;
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
            'Register Session',
            'Register Cash-Up',
            'System Expected',
            'Operator Counted',
            'Expected Total',
            'Counted Total',
            'Variance',
            'Variance Status',
            'Variance Reason',
            'Balanced',
            'With Variance',
            'Closing Note',
            'Closed By',
            'Opened By',
            'Refund Payment Method',
            'Not recorded',
            'Cash register closed and reconciled successfully',
            'Cash register is already closed.',
            'Enter a valid counted amount for every tender.',
            'A variance reason is required when counted and expected amounts differ.',
            'Close this register with the counted tender amounts?',
            'Closing...',
            'Tender snapshot is not available for this legacy closed register.',
            'The cash register closed before this payment could be recorded. Reopen the register and retry.',
            'The cash register closed before this financial movement could be recorded. Reopen the register and retry.',
        ];

        foreach ($translations as $translation) {
            $exists = DB::table('translations')
                ->where('locale', 'en')
                ->where('group', 'db')
                ->where('key', $translation)
                ->exists();

            if (!$exists) {
                DB::table('translations')->insert([
                    'locale' => 'en',
                    'group' => 'db',
                    'key' => $translation,
                    'value' => $translation,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Translation::forgetCachedTranslations();
    }

    public function down(): void
    {
        // Deliberately preserve translations because pre-existing customer values
        // cannot be distinguished safely from rows inserted by this migration.
    }
};
