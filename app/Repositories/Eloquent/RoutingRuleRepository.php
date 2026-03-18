<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\RoutingRuleQueryBuilder;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\RoutingRule;

final readonly class RoutingRuleRepository implements RoutingRuleRepositoryInterface
{
    public function create(array $attributes): RoutingRule
    {
        return RoutingRule::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): RoutingRule
    {
        return RoutingRuleQueryBuilder::make()
            ->forMerchant($merchantAccountId)
            ->whereKey($key)
            ->firstOrFail();
    }

    public function update(RoutingRule $rule, array $attributes): RoutingRule
    {
        $rule->update($attributes);

        return $rule;
    }

    public function delete(RoutingRule $rule): void
    {
        $rule->delete();
    }

    public function getActiveByMerchant(int|string $merchantAccountId): Collection
    {
        return RoutingRuleQueryBuilder::make()
            ->forMerchant($merchantAccountId)
            ->active()
            ->orderByPriority()
            ->get();
    }

    public function getAllByMerchant(int|string $merchantAccountId): Collection
    {
        return RoutingRuleQueryBuilder::make()
            ->forMerchant($merchantAccountId)
            ->orderByPriority()
            ->get();
    }
}
