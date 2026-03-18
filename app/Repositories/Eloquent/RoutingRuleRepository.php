<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\RoutingRuleQueryBuilder;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\RoutingRule;

final readonly class RoutingRuleRepository implements RoutingRuleRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(RoutingRule $rule, array $attributes): RoutingRule
    {
        $rule->update($attributes);

        return $rule;
    }

    public function delete(RoutingRule $rule): void
    {
        $rule->delete();
    }

    /**
     * @return Collection<int, RoutingRule>
     */
    public function getActiveByMerchant(int|string $merchantAccountId): Collection
    {
        return RoutingRuleQueryBuilder::make()
            ->forMerchant($merchantAccountId)
            ->active()
            ->orderByPriority()
            ->get();
    }

    /**
     * @return Collection<int, RoutingRule>
     */
    public function getAllByMerchant(int|string $merchantAccountId): Collection
    {
        return RoutingRuleQueryBuilder::make()
            ->forMerchant($merchantAccountId)
            ->orderByPriority()
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, RoutingRule>
     */
    public function paginateByMerchant(int|string $merchantAccountId, int $perPage = 20): LengthAwarePaginator
    {
        return RoutingRuleQueryBuilder::make()
            ->forMerchant($merchantAccountId)
            ->orderByPriority()
            ->paginate($perPage);
    }
}
