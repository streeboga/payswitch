<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

enum ConnectorType: string
{
    case FizOperations = 'fiz_operations';
    case PayoutProcessor = 'payout_processor';
}
