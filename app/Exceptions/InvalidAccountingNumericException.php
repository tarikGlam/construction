<?php

namespace App\Exceptions;

use InvalidArgumentException;

class InvalidAccountingNumericException extends InvalidArgumentException
{
    public function __construct(string $message = "Invalid numeric input for accounting arithmetic.")
    {
        parent::__construct($message);
    }
}
