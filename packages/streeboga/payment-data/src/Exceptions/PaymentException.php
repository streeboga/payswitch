<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Exceptions;

use Exception;

class PaymentException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly string $errorType,
        public readonly int $httpStatus = 400,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
