<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

use Streeboga\PaymentData\Enums\SessionResultType;

final readonly class DirectMethod
{
    public function __construct(
        public SessionResultType $sessionType,
    ) {}
}
