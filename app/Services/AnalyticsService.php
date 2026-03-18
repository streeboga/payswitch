<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Analytics\PeriodFilter;
use App\Repositories\Contracts\AnalyticsRepositoryInterface;

final readonly class AnalyticsService
{
    public function __construct(
        private AnalyticsRepositoryInterface $repository,
    ) {}

    public function overview(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->overview($merchantId, $period);
    }

    public function charts(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->charts($merchantId, $period);
    }

    public function funnel(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->funnel($merchantId, $period);
    }

    public function paymentMethods(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->paymentMethods($merchantId, $period);
    }

    public function failureReasons(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->failureReasons($merchantId, $period);
    }
}
