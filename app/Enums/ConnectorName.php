<?php

declare(strict_types=1);

namespace App\Enums;

enum ConnectorName: string
{
    case Stripe = 'stripe';
    case CloudPayments = 'cloudpayments';
    case Test = 'test';
}
