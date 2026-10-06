<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\Refund;

interface RefundRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Refund;

    public function findByKey(string $key, int|string $merchantAccountId): Refund;

    public function findByIdempotencyKey(string $idempotencyKey, int|string $merchantAccountId): ?Refund;

    public function sumSucceededForPayment(int $paymentIntentId): int;

    public function sumPendingAndSucceededForPayment(int $paymentIntentId): int;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Refund>
     */
    public function paginateFiltered(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    public function findByConnectorRefundId(string $connectorRefundId, int|string $merchantAccountId): ?Refund;

    /** Our own refund still waiting for the provider's id, the same amount — locked. */
    public function findPendingUnmatchedLocked(int $paymentIntentId, int $amount): ?Refund;

    public function findByIdLocked(int $id): ?Refund;

    /**
     * Pending-возвраты с id провайдера, о которых пора его спросить: опрошены меньше
     * $maxAttempts раз и срок следующего опроса настал (первый — когда возврат не
     * трогали с $firstBefore: ответ на сам запрос возврата ещё может писаться).
     *
     * @return Collection<int, Refund>
     */
    public function dueForProviderPoll(int $maxAttempts, \DateTimeInterface $firstBefore, int $limit): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateRefund(Refund $refund, array $attributes): Refund;
}
