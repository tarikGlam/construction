<?php

namespace App\Exceptions;

use RangeException;

class AccountingAmountOverflowException extends RangeException
{
    public function __construct(string $message = "Accounting amount exceeds maximum storage capacity of DECIMAL(15,4).")
    {
        parent::__construct($message);
    }
}
