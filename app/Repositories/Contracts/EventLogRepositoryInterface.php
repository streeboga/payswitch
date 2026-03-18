<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EventLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForMerchant(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator;
}
