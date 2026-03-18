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

    /**
     * @return array<string, int|float>
     */
    public function overview(int|string $merchantId, PeriodFilter $period): array
    {
        $data = $this->repository->overview($merchantId, $period);

        $data['success_rate'] = $data['total_count'] > 0
            ? round(($data['successful_count'] / $data['total_count']) * 100, 2)
            : 0;

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function charts(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->charts($merchantId, $period);
    }

    /**
     * @return array<string, int>
     */
    public function funnel(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->funnel($merchantId, $period);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paymentMethods(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->paymentMethods($merchantId, $period);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function failureReasons(int|string $merchantId, PeriodFilter $period): array
    {
        return $this->repository->failureReasons($merchantId, $period);
    }
}
