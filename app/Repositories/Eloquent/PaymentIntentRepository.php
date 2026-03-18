<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\PaymentIntent;

final class PaymentIntentRepository implements PaymentIntentRepositoryInterface
{
    public function create(array $attributes): PaymentIntent
    {
        return PaymentIntent::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): PaymentIntent
    {
        return PaymentIntent::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
    }

    public function findByKeyLocked(string $key, int|string $merchantAccountId): PaymentIntent
    {
        return PaymentIntent::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function findByKeyOrNull(string $key, int|string $merchantAccountId): ?PaymentIntent
    {
        return PaymentIntent::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
            ->first();
    }

    public function update(PaymentIntent $payment, array $attributes): PaymentIntent
    {
        $payment->update($attributes);

        return $payment;
    }

    public function paginate(int|string $merchantAccountId, array $filters = [], ?string $sort = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = PaymentIntent::where('merchant_account_id', $merchantAccountId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if ($sort) {
            $query->orderBy($sort);
        } else {
            $query->latest();
        }

        return $query->paginate($perPage);
    }

    public function paginateAll(int $perPage = 20): LengthAwarePaginator
    {
        return PaymentIntent::query()
            ->latest()
            ->paginate($perPage);
    }

    public function findByKeyGlobal(string $key): PaymentIntent
    {
        return PaymentIntent::where('key', $key)->firstOrFail();
    }

    public function findByIdLocked(int $id): ?PaymentIntent
    {
        return PaymentIntent::where('id', $id)->lockForUpdate()->first();
    }
}
