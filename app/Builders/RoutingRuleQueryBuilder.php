<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\RoutingRule;

final class RoutingRuleQueryBuilder
{
    private Builder $query;

    public function __construct()
    {
        $this->query = RoutingRule::query();
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

    public function active(): self
    {
        $this->query->where('active', true);

        return $this;
    }

    public function orderByPriority(): self
    {
        $this->query->orderByDesc('priority');

        return $this;
    }

    public function firstOrFail(): RoutingRule
    {
        return $this->query->firstOrFail();
    }

    public function get(): Collection
    {
        return $this->query->get();
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
