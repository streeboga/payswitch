<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\Analytics\PeriodFilter;

interface AnalyticsRepositoryInterface
{
    /**
     * @return array<string, int>
     */
    public function overview(int|string $merchantId, PeriodFilter $period): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function charts(int|string $merchantId, PeriodFilter $period): array;

    /**
     * @return array<string, int>
     */
    public function funnel(int|string $merchantId, PeriodFilter $period): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paymentMethods(int|string $merchantId, PeriodFilter $period): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function failureReasons(int|string $merchantId, PeriodFilter $period): array;
}
