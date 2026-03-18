<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Exceptions;

class InvalidStateTransitionException extends PaymentException
{
    public function __construct(
        string $from,
        string $to,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            message: "Invalid state transition from '{$from}' to '{$to}'.",
            errorCode: 'INVALID_STATE_TRANSITION',
            errorType: 'state_error',
            httpStatus: 400,
            code: $code,
            previous: $previous,
        );
    }
}
