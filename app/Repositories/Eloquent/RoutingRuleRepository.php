<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\RoutingRule;

final class RoutingRuleRepository implements RoutingRuleRepositoryInterface
{
    public function create(array $attributes): RoutingRule
    {
        return RoutingRule::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): RoutingRule
    {
        return RoutingRule::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
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
        return RoutingRule::where('merchant_account_id', $merchantAccountId)
            ->where('active', true)
            ->orderByDesc('priority')
            ->get();
    }

    public function getAllByMerchant(int|string $merchantAccountId): Collection
    {
        return RoutingRule::where('merchant_account_id', $merchantAccountId)
            ->orderByDesc('priority')
            ->get();
    }
}
