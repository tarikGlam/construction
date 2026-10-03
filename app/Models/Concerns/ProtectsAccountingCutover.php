<?php

namespace App\Models\Concerns;

use App\Models\AccountingConfig;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

trait ProtectsAccountingCutover
{
    public static function bootProtectsAccountingCutover(): void
    {
        $assertMutable = static function (Model $model): void {
            $config = AccountingConfig::find(1);
            if (!$config?->enabled || $config->status !== 'active' || !$config->cutover_at || !$model->created_at || !$model->created_at->lt($config->cutover_at)) {
                return;
            }

            // Existing-business cutovers may fold pre-cutover operational rows into
            // an approved opening position even when those individual rows do not
            // have their own journal. Those records must remain immutable.
            if ($config->activation_mode === 'existing_business' || $config->opening_journal_entry_id) {
                throw new RuntimeException(__('db.Pre-cutover records cannot be edited or deleted after accounting setup. Create a supported post-cutover adjustment instead.'));
            }

            // A new-business cutover has no opening snapshot to protect. If this
            // specific pre-cutover source was never posted, changing/deleting it
            // cannot invalidate the ledger. This covers setup-window records such
            // as an expense entered minutes before accounting was activated.
            $sourceTypes = array_unique([
                get_class($model),
                class_basename($model),
                '\\' . ltrim(get_class($model), '\\'),
            ]);
            $hasJournalHistory = JournalEntry::whereIn('source_type', $sourceTypes)
                ->where('source_id', $model->getKey())
                ->exists();

            if (!$hasJournalHistory) {
                return;
            }

            throw new RuntimeException(__('db.Pre-cutover records cannot be edited or deleted after accounting setup. Create a supported post-cutover adjustment instead.'));
        };

        static::updating($assertMutable);
        static::deleting($assertMutable);
    }
}
