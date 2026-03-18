<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\PaymentMethodQueryBuilder;
use App\Repositories\Contracts\PaymentMethodRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\PaymentMethod;

final readonly class PaymentMethodRepository implements PaymentMethodRepositoryInterface
{
    public function create(array $attributes): PaymentMethod
    {
        return PaymentMethod::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): PaymentMethod
    {
        return PaymentMethodQueryBuilder::make()
            ->whereKey($key)
            ->forMerchant($merchantAccountId)
            ->firstOrFail();
    }

    public function findByCustomer(int $customerId, int|string $merchantAccountId): Collection
    {
        return PaymentMethodQueryBuilder::make()
            ->forCustomer($customerId)
            ->forMerchant($merchantAccountId)
            ->get();
    }

    public function delete(PaymentMethod $paymentMethod): void
    {
        $paymentMethod->delete();
    }

    public function unsetDefaultForCustomer(int $customerId, int|string $merchantAccountId, int $excludeId): void
    {
        PaymentMethodQueryBuilder::make()
            ->forCustomer($customerId)
            ->forMerchant($merchantAccountId)
            ->excludeId($excludeId)
            ->update(['is_default' => false]);
    }

    public function update(PaymentMethod $paymentMethod, array $attributes): PaymentMethod
    {
        $paymentMethod->update($attributes);

        return $paymentMethod;
    }
}
