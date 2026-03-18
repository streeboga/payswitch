<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

final readonly class DashboardPaymentService
{
    public function __construct(
        private PaymentIntentRepositoryInterface $repository,
    ) {}

    public function list(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginateFiltered($merchantId, $filters, $perPage);
    }

    public function exportCursor(int|string $merchantId, array $filters = []): LazyCollection
    {
        return $this->repository->filteredQuery($merchantId, $filters)->cursor();
    }
}
