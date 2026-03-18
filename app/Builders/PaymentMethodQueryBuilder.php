<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\PaymentMethod;

final class PaymentMethodQueryBuilder
{
    /** @var Builder<PaymentMethod> */
    private Builder $query;

    public function __construct()
    {
        $this->query = PaymentMethod::query();
    }

    public static function make(): self
    {
        return new self;
    }

    public function forMerchant(int|string $merchantAccountId): self
    {
        $this->query->where('merchant_account_id', $merchantAccountId);

        return $this;
    }

    public function whereKey(string $key): self
    {
        $this->query->where('key', $key);

        return $this;
    }

    public function forCustomer(int $customerId): self
    {
        $this->query->where('customer_id', $customerId);

        return $this;
    }

    public function excludeId(int $excludeId): self
    {
        $this->query->where('id', '!=', $excludeId);

        return $this;
    }

    public function firstOrFail(): PaymentMethod
    {
        return $this->query->firstOrFail();
    }

    /** @return Collection<int, PaymentMethod> */
    public function get(): Collection
    {
        return $this->query->get();
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): int
    {
        return $this->query->update($attributes);
    }

    /** @return Builder<PaymentMethod> */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
