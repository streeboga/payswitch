<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\Organization;

final class OrganizationQueryBuilder
{
    /** @var Builder<Organization> */
    private Builder $query;

    public function __construct()
    {
        $this->query = Organization::query();
    }

    public static function make(): self
    {
        return new self;
    }

    public function whereKey(string $key): self
    {
        $this->query->where('key', $key);

        return $this;
    }

    public function search(string $term): self
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);
        $this->query->where('name', 'like', "%{$escaped}%");

        return $this;
    }

    public function withMerchantCount(): self
    {
        $this->query->withCount('merchantAccounts');

        return $this;
    }

    public function sortBy(string $column, string $direction = 'asc'): self
    {
        $allowed = ['created_at', 'updated_at', 'name'];
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

    public function firstOrFail(): Organization
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?Organization
    {
        return $this->query->first();
    }

    /** @return Collection<int, Organization> */
    public function get(): Collection
    {
        return $this->query->get();
    }

    /** @return LengthAwarePaginator<int, Organization> */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }

    /** @return Builder<Organization> */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
