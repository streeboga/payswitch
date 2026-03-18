<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

enum ApiKeyType: string
{
    case Admin = 'admin';
    case Secret = 'secret';
    case Publishable = 'publishable';
}
