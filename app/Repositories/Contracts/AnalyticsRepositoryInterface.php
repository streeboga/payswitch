<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\Analytics\PeriodFilter;

interface AnalyticsRepositoryInterface
{
    public function overview(int|string $merchantId, PeriodFilter $period): array;

    public function charts(int|string $merchantId, PeriodFilter $period): array;

    public function funnel(int|string $merchantId, PeriodFilter $period): array;

    public function paymentMethods(int|string $merchantId, PeriodFilter $period): array;

    public function failureReasons(int|string $merchantId, PeriodFilter $period): array;
}
