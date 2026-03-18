<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use Streeboga\PaymentData\Models\PaymentIntent;

final readonly class DashboardPaymentService
{
    public function __construct(
        private PaymentIntentRepositoryInterface $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PaymentIntent>
     */
    public function list(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginateFiltered($merchantId, $filters, $perPage);
    }

    public function find(string $paymentKey, int|string $merchantId): PaymentIntent
    {
        return $this->repository->findByKey($paymentKey, $merchantId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, PaymentIntent>
     */
    public function exportCursor(int|string $merchantId, array $filters = []): LazyCollection
    {
        return $this->repository->filteredQuery($merchantId, $filters)->cursor();
    }
}
