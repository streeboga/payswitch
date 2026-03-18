<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\RoutingRule;

interface RoutingRuleRepositoryInterface
{
    public function create(array $attributes): RoutingRule;

    public function findByKey(string $key, int|string $merchantAccountId): RoutingRule;

    public function update(RoutingRule $rule, array $attributes): RoutingRule;

    public function delete(RoutingRule $rule): void;

    public function getActiveByMerchant(int|string $merchantAccountId): Collection;

    public function getAllByMerchant(int|string $merchantAccountId): Collection;
}
