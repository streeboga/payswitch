<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\Customer;

final class CustomerRepository implements CustomerRepositoryInterface
{
    public function create(array $attributes): Customer
    {
        return Customer::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): Customer
    {
        return Customer::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
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
        return Customer::where('merchant_account_id', $merchantAccountId)->paginate($perPage);
    }

    public function existsByKey(string $key, int|string $merchantAccountId): bool
    {
        return Customer::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
            ->exists();
    }

    public function list(int|string $merchantAccountId): Collection
    {
        return Customer::where('merchant_account_id', $merchantAccountId)->get();
    }
}
