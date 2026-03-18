<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\RefundRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class DashboardRefundService
{
    public function __construct(
        private RefundRepositoryInterface $repository,
    ) {}

    public function list(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginateFiltered($merchantId, $filters, $perPage);
    }
}
