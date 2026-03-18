<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\RoutingRule;

interface RoutingRuleRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): RoutingRule;

    public function findByKey(string $key, int|string $merchantAccountId): RoutingRule;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(RoutingRule $rule, array $attributes): RoutingRule;

    public function delete(RoutingRule $rule): void;

    /**
     * @return Collection<int, RoutingRule>
     */
    public function getActiveByMerchant(int|string $merchantAccountId): Collection;

    /**
     * @return Collection<int, RoutingRule>
     */
    public function getAllByMerchant(int|string $merchantAccountId): Collection;

    /**
     * @return LengthAwarePaginator<int, RoutingRule>
     */
    public function paginateByMerchant(int|string $merchantAccountId, int $perPage = 20): LengthAwarePaginator;
}
