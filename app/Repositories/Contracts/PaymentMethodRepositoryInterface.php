<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\PaymentMethod;

interface PaymentMethodRepositoryInterface
{
    public function create(array $attributes): PaymentMethod;

    public function findByKey(string $key, int|string $merchantAccountId): PaymentMethod;

    public function findByCustomer(int $customerId, int|string $merchantAccountId): Collection;

    public function delete(PaymentMethod $paymentMethod): void;

    public function unsetDefaultForCustomer(int $customerId, int|string $merchantAccountId, int $excludeId): void;

    public function update(PaymentMethod $paymentMethod, array $attributes): PaymentMethod;
}
