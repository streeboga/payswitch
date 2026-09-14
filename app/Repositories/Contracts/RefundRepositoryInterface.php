<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\Refund;

interface RefundRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Refund;

    public function findByKey(string $key, int|string $merchantAccountId): Refund;

    public function sumSucceededForPayment(int $paymentIntentId): int;

    public function sumPendingAndSucceededForPayment(int $paymentIntentId): int;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Refund>
     */
    public function paginateFiltered(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function findByConnectorRefundId(string $connectorRefundId, int|string $merchantAccountId): ?Refund;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateRefund(Refund $refund, array $attributes): Refund;
}
