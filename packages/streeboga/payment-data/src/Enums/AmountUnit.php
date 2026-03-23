<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

enum AmountUnit: string
{
    case Rubles = 'rubles';
    case Kopecks = 'kopecks';
    case MinorUnits = 'minor';
}
