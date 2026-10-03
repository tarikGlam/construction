<?php

namespace App\Exceptions;

use RuntimeException;

class MissingCurrencyMetadataException extends RuntimeException
{
    public function __construct(string $message = "Missing currency metadata on currency-aware accounting source.")
    {
        parent::__construct($message);
    }
}
