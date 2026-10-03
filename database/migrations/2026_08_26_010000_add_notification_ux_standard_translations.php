<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Translation;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('translations')) {
            return;
        }

        $newTranslations = [
            ['key' => 'Confirmation', 'value' => 'Confirmation'],
            ['key' => 'Delete Confirmation', 'value' => 'Delete Confirmation'],
            ['key' => 'Are you sure want to delete?', 'value' => 'Are you sure want to delete?'],
            ['key' => 'Are you sure you want to proceed?', 'value' => 'Are you sure you want to proceed?'],
            ['key' => 'Confirm', 'value' => 'Confirm'],
            ['key' => 'We could not complete this action. Please try again.', 'value' => 'We could not complete this action. Please try again.'],
            ['key' => 'This record cannot be deleted because it is already being used.', 'value' => 'This record cannot be deleted because it is already being used.'],
            ['key' => 'You do not have permission to perform this action.', 'value' => 'You do not have permission to perform this action.'],
            ['key' => 'Please select Warehouse!', 'value' => 'Please select Warehouse!'],
            ['key' => 'Please select Customer!', 'value' => 'Please select Customer!'],
            ['key' => 'Please select Product', 'value' => 'Please select Product'],
            ['key' => 'Please insert product to order table!', 'value' => 'Please insert product to order table!'],
            ['key' => 'Quantity exceeds stock quantity!', 'value' => 'Quantity exceeds stock quantity!'],
            ['key' => 'Nothing is selected!', 'value' => 'Nothing is selected!'],
            ['key' => 'This feature is disable for demo!', 'value' => 'This feature is disable for demo!'],
            ['key' => 'Exported to CSV file successfully! Click Ok to download file', 'value' => 'Exported to CSV file successfully! Click Ok to download file'],
            ['key' => 'Please make another account default first!', 'value' => 'Please make another account default first!'],
            ['key' => 'Paying amount cannot be bigger than recieved amount', 'value' => 'Paying amount cannot be bigger than received amount'],
            ['key' => 'Paying amount cannot be bigger than due amount', 'value' => 'Paying amount cannot be bigger than due amount'],
            ['key' => 'Amount exceeds customer deposit!', 'value' => 'Amount exceeds customer deposit!'],
            ['key' => 'Amount exceeds card balance!', 'value' => 'Amount exceeds card balance!'],
            ['key' => 'This card is expired!', 'value' => 'This card is expired!'],
            ['key' => 'Duplicate input is not allowed!', 'value' => 'Duplicate input is not allowed!'],
            ['key' => 'If you delete category all products under this category will also be deleted. Are you sure want to delete?', 'value' => 'If you delete this category, all products under this category will also be deleted. Are you sure want to delete?'],
            ['key' => 'Are you sure want to close?', 'value' => 'Are you sure want to close?'],
            ['key' => 'Are you sure you want to cancel?', 'value' => 'Are you sure you want to cancel?'],
            ['key' => 'Changing warehouse will clear your current cart. Do you want to proceed?', 'value' => 'Changing warehouse will clear your current cart. Do you want to proceed?'],
            ['key' => 'Proceed', 'value' => 'Proceed'],
            ['key' => 'Accounting activation has been reset.', 'value' => 'Accounting activation has been reset.'],
            ['key' => 'Delete this accounting account? Protected accounts will be blocked automatically.', 'value' => 'Delete this accounting account? Protected accounts will be blocked automatically.'],
            ['key' => 'Are you sure you want to permanently delete the selected records? This action cannot be undone!', 'value' => 'Are you sure you want to permanently delete the selected records? This action cannot be undone!'],
            ['key' => 'Notifications', 'value' => 'Notifications'],
            ['key' => 'Reset', 'value' => 'Reset'],
        ];

        foreach ($newTranslations as $item) {
            $exists = DB::table('translations')
                ->where('locale', 'en')
                ->where('group', 'db')
                ->where('key', $item['key'])
                ->exists();

            if (!$exists) {
                DB::table('translations')->insert([
                    'locale' => 'en',
                    'group' => 'db',
                    'key' => $item['key'],
                    'value' => $item['value'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (class_exists(Translation::class)) {
            Translation::forgetCachedTranslations();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('translations')) {
            return;
        }

        $keys = [
            'Confirmation',
            'Delete Confirmation',
            'Changing warehouse will clear your current cart. Do you want to proceed?',
            'Proceed',
        ];

        DB::table('translations')
            ->where('locale', 'en')
            ->where('group', 'db')
            ->whereIn('key', $keys)
            ->delete();

        if (class_exists(Translation::class)) {
            Translation::forgetCachedTranslations();
        }
    }
};
