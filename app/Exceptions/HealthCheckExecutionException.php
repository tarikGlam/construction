<?php

namespace App\Exceptions;

use RuntimeException;

class HealthCheckExecutionException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly string $failedStage,
        string $message,
        ?\Throwable $previous = null,
        public readonly array $diagnostics = []
    ) {
        parent::__construct($message, 0, $previous);
    }
}
