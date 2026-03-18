<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\Customer;

interface CustomerRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Customer;

    public function findByKey(string $key, int|string $merchantAccountId): Customer;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Customer $customer, array $attributes): Customer;

    public function delete(Customer $customer): void;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function existsByKey(string $key, int|string $merchantAccountId): bool;

    /**
     * @return Collection<int, Customer>
     */
    public function list(int|string $merchantAccountId): Collection;
}
