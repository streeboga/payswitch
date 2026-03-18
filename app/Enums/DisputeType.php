<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeType: string
{
    case Chargeback = 'chargeback';
    case Inquiry = 'inquiry';
    case Fraud = 'fraud';
}
