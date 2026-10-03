<?php

namespace App\Exceptions;

use RuntimeException;

class CurrencyRateResolutionException extends RuntimeException
{
    public function __construct(string $message = "Exchange rate could not be resolved or is invalid for foreign transaction.")
    {
        parent::__construct($message);
    }
}
