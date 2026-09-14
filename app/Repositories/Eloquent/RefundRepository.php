<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\RefundQueryBuilder;
use App\Repositories\Contracts\RefundRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Refund;

final readonly class RefundRepository implements RefundRepositoryInterface
{
    private function query(): RefundQueryBuilder
    {
        return RefundQueryBuilder::make();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Refund
    {
        return Refund::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): Refund
    {
        return $this->query()->forMerchant($merchantAccountId)->whereKey($key)->firstOrFail();
    }

    public function sumSucceededForPayment(int $paymentIntentId): int
    {
        return $this->query()->forPaymentIntent($paymentIntentId)->withStatus(RefundStatus::Succeeded)->sumAmount();
    }

    public function sumPendingAndSucceededForPayment(int $paymentIntentId): int
    {
        // Need two statuses — use the query builder for one, then union manually
        return (int) Refund::where('payment_intent_id', $paymentIntentId)
            ->whereIn('status', [RefundStatus::Succeeded, RefundStatus::Pending])
            ->sum('amount');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Refund>
     */
    public function paginateFiltered(int|string $merchantAccountId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $builder = $this->query()->forMerchant($merchantAccountId);

        if (! empty($filters['payment_id'])) {
            $builder->forPaymentKey((string) $filters['payment_id']);
        }
        if (! empty($filters['status'])) {
            $builder->withStatus($filters['status']);
        }
        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $builder->createdBetween($filters['from'], $filters['to']);
        }
        if (! empty($filters['search'])) {
            $builder->search($filters['search']);
        }
        if (! empty($filters['sort'])) {
            $builder->sortBy($filters['sort']);
        } else {
            $builder->latest();
        }

        return $builder->with('paymentIntent')->paginate($perPage);
    }

    public function findByConnectorRefundId(string $connectorRefundId, int|string $merchantAccountId): ?Refund
    {
        // Ids are the provider's, not ours: two merchants on the same PSP can share one.
        return $this->query()->forMerchant($merchantAccountId)->getQuery()
            ->where('connector_refund_id', $connectorRefundId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateRefund(Refund $refund, array $attributes): Refund
    {
        $refund->update($attributes);

        return $refund;
    }
}
