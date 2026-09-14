<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Builders\PaymentIntentQueryBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Streeboga\PaymentData\Enums\PaymentStatus;
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
     */
    public function findExpiredInStatuses(array $statuses): Builder;

    /**
     * Платежи в статусах, изменённые в окне [$from, $to], по текущему статусу
     * которых нет ни одного платёжного webhook_events.
     *
     * @param  array<int, PaymentStatus>  $statuses
     */
    public function findWithoutWebhookForCurrentStatus(array $statuses, \DateTimeInterface $from, \DateTimeInterface $to): Builder;
}
