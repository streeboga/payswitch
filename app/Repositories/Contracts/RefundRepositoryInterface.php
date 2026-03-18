<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Streeboga\PaymentData\Models\Refund;

interface RefundRepositoryInterface
{
    public function create(array $attributes): Refund;

    public function findByKey(string $key, int|string $merchantAccountId): Refund;

    public function sumSucceededForPayment(int $paymentIntentId): int;

    public function sumPendingAndSucceededForPayment(int $paymentIntentId): int;
}
