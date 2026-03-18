<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\Customer;

interface CustomerRepositoryInterface
{
    public function create(array $attributes): Customer;

    public function findByKey(string $key, int|string $merchantAccountId): Customer;

    public function update(Customer $customer, array $attributes): Customer;

    public function delete(Customer $customer): void;

    public function paginate(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function existsByKey(string $key, int|string $merchantAccountId): bool;

    public function list(int|string $merchantAccountId): Collection;
}
