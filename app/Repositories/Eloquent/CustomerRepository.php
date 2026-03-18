<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\CustomerQueryBuilder;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\Customer;

final class CustomerRepository implements CustomerRepositoryInterface
{
    private function query(): CustomerQueryBuilder
    {
        return CustomerQueryBuilder::make();
    }

    public function create(array $attributes): Customer
    {
        return Customer::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): Customer
    {
        return $this->query()->forMerchant($merchantAccountId)->whereKey($key)->firstOrFail();
    }

    public function update(Customer $customer, array $attributes): Customer
    {
        $customer->update($attributes);

        return $customer;
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    public function paginate(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->query()->forMerchant($merchantAccountId)->paginate($perPage);
    }

    public function existsByKey(string $key, int|string $merchantAccountId): bool
    {
        return $this->query()->forMerchant($merchantAccountId)->whereKey($key)->exists();
    }

    public function list(int|string $merchantAccountId): Collection
    {
        return $this->query()->forMerchant($merchantAccountId)->getQuery()->get();
    }
}
