<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\PaymentIntent;

interface PaymentIntentRepositoryInterface
{
    public function create(array $attributes): PaymentIntent;

    public function findByKey(string $key, int|string $merchantAccountId): PaymentIntent;

    public function findByKeyLocked(string $key, int|string $merchantAccountId): PaymentIntent;

    public function findByKeyOrNull(string $key, int|string $merchantAccountId): ?PaymentIntent;

    public function update(PaymentIntent $payment, array $attributes): PaymentIntent;

    public function paginate(int|string $merchantAccountId, array $filters = [], ?string $sort = null, int $perPage = 20): LengthAwarePaginator;

    public function paginateAll(int $perPage = 20): LengthAwarePaginator;

    public function findByKeyGlobal(string $key): PaymentIntent;
}
