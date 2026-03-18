<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Builders\PaymentIntentQueryBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\PaymentMethod;

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

    public function findByIdLocked(int $id): ?PaymentIntent;

    public function incrementAttemptCount(PaymentIntent $payment): void;

    public function createAttempt(PaymentIntent $payment, array $data): void;

    public function findLastSuccessfulAttempt(PaymentIntent $payment): ?object;

    public function findPaymentMethodByKey(string $key, int|string $merchantAccountId): ?PaymentMethod;

    public function paginateFiltered(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function filteredQuery(int|string $merchantAccountId, array $filters = []): PaymentIntentQueryBuilder;
}
