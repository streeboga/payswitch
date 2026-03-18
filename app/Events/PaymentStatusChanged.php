<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Streeboga\PaymentData\Models\PaymentIntent;

class PaymentStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly PaymentIntent $payment,
        public readonly ?string $previousStatus = null,
    ) {}
}
