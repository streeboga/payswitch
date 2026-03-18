<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\RefundQueryBuilder;
use App\Repositories\Contracts\RefundRepositoryInterface;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Refund;

final class RefundRepository implements RefundRepositoryInterface
{
    private function query(): RefundQueryBuilder
    {
        return RefundQueryBuilder::make();
    }

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
}
