<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\BusinessProfile;

final class BusinessProfileQueryBuilder
{
    /** @var Builder<BusinessProfile> */
    private Builder $query;

    public function __construct()
    {
        $this->query = BusinessProfile::query();
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

    public function withMerchantAccount(): self
    {
        $this->query->with('merchantAccount');

        return $this;
    }

    public function withCounts(): self
    {
        $this->query->withCount(['connectorAccounts', 'routingRules']);

        return $this;
    }

    public function sortBy(string $column, string $direction = 'asc'): self
    {
        $allowed = ['created_at', 'updated_at'];
        if (in_array($column, $allowed, true)) {
            $this->query->orderBy($column, $direction);
        }

        return $this;
    }

    public function latest(): self
    {
        $this->query->orderByDesc('created_at');

        return $this;
    }

    public function oldest(): self
    {
        $this->query->orderBy('id');

        return $this;
    }

    public function firstOrFail(): BusinessProfile
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?BusinessProfile
    {
        return $this->query->first();
    }

    /** @return Collection<int, BusinessProfile> */
    public function get(): Collection
    {
        return $this->query->get();
    }

    /** @return LengthAwarePaginator<int, BusinessProfile> */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }

    /** @return Builder<BusinessProfile> */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
