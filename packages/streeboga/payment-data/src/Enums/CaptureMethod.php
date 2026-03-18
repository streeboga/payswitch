<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

enum CaptureMethod: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
