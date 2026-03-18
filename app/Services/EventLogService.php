<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\EventLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EventLogService
{
    public function __construct(
        private EventLogRepositoryInterface $eventLogs,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function list(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->eventLogs->paginateForMerchant($merchantId, $filters, $perPage);
    }
}
