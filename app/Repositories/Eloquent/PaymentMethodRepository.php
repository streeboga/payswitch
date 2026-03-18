<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\PaymentMethodRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\PaymentMethod;

final class PaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    public function create(array $attributes): PaymentMethod
    {
        return PaymentMethod::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): PaymentMethod
    {
        return PaymentMethod::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
    }

    public function findByCustomer(int $customerId, int|string $merchantAccountId): Collection
    {
        return PaymentMethod::where('customer_id', $customerId)
            ->where('merchant_account_id', $merchantAccountId)
            ->get();
    }

    public function delete(PaymentMethod $paymentMethod): void
    {
        $paymentMethod->delete();
    }

    public function unsetDefaultForCustomer(int $customerId, int|string $merchantAccountId, int $excludeId): void
    {
        PaymentMethod::where('customer_id', $customerId)
            ->where('merchant_account_id', $merchantAccountId)
            ->where('id', '!=', $excludeId)
            ->update(['is_default' => false]);
    }

    public function update(PaymentMethod $paymentMethod, array $attributes): PaymentMethod
    {
        $paymentMethod->update($attributes);

        return $paymentMethod;
    }
}
