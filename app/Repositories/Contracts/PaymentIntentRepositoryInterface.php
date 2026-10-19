<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Builders\PaymentIntentQueryBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentAction;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\PaymentMethod;

interface PaymentIntentRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PaymentIntent;

    public function findByKey(string $key, int|string $merchantAccountId): PaymentIntent;

    public function findByKeyLocked(string $key, int|string $merchantAccountId): PaymentIntent;

    public function findByKeyOrNull(string $key, int|string $merchantAccountId): ?PaymentIntent;

    public function findByIdempotencyKey(string $idempotencyKey, int|string $merchantAccountId): ?PaymentIntent;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PaymentIntent $payment, array $attributes): PaymentIntent;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PaymentIntent>
     */
    public function paginate(int|string $merchantAccountId, array $filters = [], ?string $sort = null, int $perPage = 20): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, PaymentIntent>
     */
    public function paginateAll(int $perPage = 20): LengthAwarePaginator;

    public function findByKeyGlobal(string $key): PaymentIntent;

    public function findByIdLocked(int $id): ?PaymentIntent;

    public function incrementAttemptCount(PaymentIntent $payment): void;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAttempt(PaymentIntent $payment, array $data): void;

    public function findLastSuccessfulAttempt(PaymentIntent $payment): ?PaymentAttempt;

    public function findLastAttemptWithTransaction(PaymentIntent $payment): ?PaymentAttempt;

    /** Захват или отмена, уже проведённые над платежом с этим ключом идемпотентности. */
    public function findAction(PaymentIntent $payment, string $idempotencyKey): ?PaymentAction;

    public function recordAction(PaymentIntent $payment, string $idempotencyKey, string $action, ?int $amount): void;

    public function findPaymentMethodByKey(string $key, int|string $merchantAccountId): ?PaymentMethod;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PaymentIntent>
     */
    public function paginateFiltered(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(int|string $merchantAccountId, array $filters = []): PaymentIntentQueryBuilder;

    /**
     * @param  array<int, PaymentStatus>  $statuses
     * @return Builder<PaymentIntent>
     */
    public function findExpiredInStatuses(array $statuses): Builder;

    /**
     * Платежи, о которых пора спросить провайдера: в этих статусах, с транзакцией у
     * провайдера, опрошены меньше $maxAttempts раз и срок следующего опроса настал
     * (первый — когда статус держится с $firstBefore). Давно не тронутые — первыми.
     *
     * @param  array<int, PaymentStatus>  $statuses
     * @param  list<string>  $exceptConnectors
     * @return Collection<int, PaymentIntent>
     */
    public function dueForProviderPoll(array $statuses, array $exceptConnectors, int $maxAttempts, \DateTimeInterface $firstBefore, int $limit): Collection;

    /**
     * Платежи в статусах, изменённые в окне [$from, $to], по текущему статусу
     * которых нет ни одного платёжного webhook_events.
     *
     * @param  array<int, PaymentStatus>  $statuses
     * @return Builder<PaymentIntent>
     */
    public function findWithoutWebhookForCurrentStatus(array $statuses, \DateTimeInterface $from, \DateTimeInterface $to): Builder;
}
