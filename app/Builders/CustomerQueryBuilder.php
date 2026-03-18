<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Streeboga\PaymentData\Models\Customer;

final class CustomerQueryBuilder
{
    private Builder $query;

    public function __construct()
    {
        $this->query = Customer::query();
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

    public function whereEmail(string $email): self
    {
        $this->query->where('email', $email);

        return $this;
    }

    public function search(string $term): self
    {
        $this->query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('key', 'like', "%{$term}%");
        });

        return $this;
    }

    public function sortBy(string $column, string $direction = 'asc'): self
    {
        $allowed = ['created_at', 'updated_at', 'name', 'email'];
        if (in_array($column, $allowed, true)) {
            $this->query->orderBy($column, $direction);
        }

        return $this;
    }

    public function exists(): bool
    {
        return $this->query->exists();
    }

    public function firstOrFail(): Customer
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?Customer
    {
        return $this->query->first();
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
