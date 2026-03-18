<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\PaymentIntentQueryBuilder;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\PaymentMethod;

final class PaymentIntentRepository implements PaymentIntentRepositoryInterface
{
    private function query(): PaymentIntentQueryBuilder
    {
        return PaymentIntentQueryBuilder::make();
    }

    public function create(array $attributes): PaymentIntent
    {
        return PaymentIntent::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): PaymentIntent
    {
        return $this->query()->forMerchant($merchantAccountId)->byKey($key)->firstOrFail();
    }

    public function findByKeyLocked(string $key, int|string $merchantAccountId): PaymentIntent
    {
        return $this->query()->forMerchant($merchantAccountId)->byKey($key)->locked()->firstOrFail();
    }

    public function findByKeyOrNull(string $key, int|string $merchantAccountId): ?PaymentIntent
    {
        return $this->query()->forMerchant($merchantAccountId)->byKey($key)->first();
    }

    public function update(PaymentIntent $payment, array $attributes): PaymentIntent
    {
        $payment->update($attributes);

        return $payment;
    }

    public function paginate(int|string $merchantAccountId, array $filters = [], ?string $sort = null, int $perPage = 20): LengthAwarePaginator
    {
        $builder = $this->query()->forMerchant($merchantAccountId);

        if (! empty($filters['status'])) {
            $builder->withStatus($filters['status']);
        }

        if (! $sort) {
            $builder->latest();
        }

        $query = $builder->getQuery();
        if ($sort) {
            $query->orderBy($sort);
        }

        return $query->paginate($perPage);
    }

    public function paginateAll(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query()->latest()->getQuery()->paginate($perPage);
    }

    public function findByKeyGlobal(string $key): PaymentIntent
    {
        return $this->query()->byKey($key)->firstOrFail();
    }

    public function findByIdLocked(int $id): ?PaymentIntent
    {
        return $this->query()->byId($id)->locked()->first();
    }

    public function incrementAttemptCount(PaymentIntent $payment): void
    {
        $payment->increment('attempt_count');
    }

    public function createAttempt(PaymentIntent $payment, array $data): void
    {
        $payment->paymentAttempts()->create($data);
    }

    public function findLastSuccessfulAttempt(PaymentIntent $payment): ?object
    {
        return $payment->paymentAttempts()->where('status', 'succeeded')->latest()->first();
    }

    public function findPaymentMethodByKey(string $key, int|string $merchantAccountId): ?PaymentMethod
    {
        return PaymentMethod::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
            ->first();
    }
}
