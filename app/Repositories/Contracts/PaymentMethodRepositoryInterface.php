<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\PaymentMethod;

interface PaymentMethodRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PaymentMethod;

    public function findByKey(string $key, int|string $merchantAccountId): PaymentMethod;

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function findByCustomer(int $customerId, int|string $merchantAccountId): Collection;

    public function delete(PaymentMethod $paymentMethod): void;

    public function unsetDefaultForCustomer(int $customerId, int|string $merchantAccountId, int $excludeId): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PaymentMethod $paymentMethod, array $attributes): PaymentMethod;
}
