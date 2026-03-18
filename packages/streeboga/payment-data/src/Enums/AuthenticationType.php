<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

enum AuthenticationType: string
{
    case ThreeDs = 'three_ds';
    case NoThreeDs = 'no_three_ds';
}
