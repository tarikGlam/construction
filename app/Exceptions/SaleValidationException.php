<?php

namespace App\Exceptions;

use RuntimeException;

class SaleValidationException extends RuntimeException
{
    protected ?string $field;

    public function __construct(string $message = "", ?string $field = null, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->field = $field;
    }

    public function getField(): ?string
    {
        return $this->field;
    }
}
