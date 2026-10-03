<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Validation before operational arithmetic; never repair historical rates here. */
class TransactionExchangeRate
{
    public function validate(mixed $rate): string
    {
        if ((!is_string($rate) && !is_int($rate) && !is_float($rate))
            || (is_float($rate) && !is_finite($rate))
            || !preg_match('/^\d+(?:\.\d{1,8})?$/D', (string) $rate)
            || bccomp((string) $rate, '0', 8) <= 0
            || bccomp((string) $rate, '999999999999.99999999', 8) > 0) {
            throw ValidationException::withMessages(['exchange_rate' => __('integrity.exchange_rate')]);
        }

        return bcadd((string) $rate, '0', 8);
    }
}
