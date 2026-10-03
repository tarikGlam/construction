<?php

namespace App\Rules;

use App\Services\TransactionExchangeRate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

class ValidTransactionExchangeRate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(TransactionExchangeRate::class)->validate($value);
        } catch (ValidationException $exception) {
            $fail(__('integrity.exchange_rate'));
        }
    }
}
