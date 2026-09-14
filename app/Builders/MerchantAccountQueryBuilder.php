<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\MerchantAccount;

final class MerchantAccountQueryBuilder
{
    /** @var Builder<MerchantAccount> */
    private Builder $query;

    public function __construct()
    {
        $this->query = MerchantAccount::query();
    }

    public static function make(): self
    {
        return new self;
    }

    public function forOrganization(int|string $orgId): self
    {
        $this->query->where('org_id', $orgId);

        return $this;
    }

    public function forUser(int $userId): self
    {
        $this->query->whereIn('org_id', UserRole::query()->select('organization_id')->where('user_id', $userId));

        return $this;
    }

    public function whereKey(string $key): self
    {
        $this->query->where('key', $key);

        return $this;
    }

    public function search(string $term): self
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);

        $this->query->where(function (Builder $q) use ($escaped) {
            $q->whereLike('name', "%{$escaped}%")
                ->orWhereLike('key', "%{$escaped}%");
        });

        return $this;
    }

    public function withOrganization(): self
    {
        $this->query->with('organization');

        return $this;
    }

    public function withCounts(): self
    {
        $this->query->withCount(['businessProfiles', 'connectorAccounts']);

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

    public function firstOrFail(): MerchantAccount
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?MerchantAccount
    {
        return $this->query->first();
    }

    /** @return Collection<int, MerchantAccount> */
    public function get(): Collection
    {
        return $this->query->get();
    }

    /** @return LengthAwarePaginator<int, MerchantAccount> */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }

    /** @return Builder<MerchantAccount> */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
