<?php

namespace App\Models\Concerns;

use App\Services\Accounting\CurrencyNormalizationService;
use App\Services\TransactionExchangeRate;

trait ValidatesTransactionExchangeRate
{
    public static function bootValidatesTransactionExchangeRate(): void
    {
        static::saving(function ($model) {
            if ($model->exists && !$model->isDirty(['currency_id', 'exchange_rate'])) return;
            if (!$model->currency_id) return; // Legacy/base-only sources retain their established contract.
            $raw = $model->getAttributes()['exchange_rate'] ?? null;
            if ((int) $model->currency_id === app(CurrencyNormalizationService::class)->getBaseCurrencyId()) {
                if ($raw !== null) app(TransactionExchangeRate::class)->validate($raw);
                $model->exchange_rate = '1.00000000';
            } else {
                $model->exchange_rate = app(TransactionExchangeRate::class)->validate($raw);
            }
        });
    }
}
