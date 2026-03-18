<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RefundRepositoryInterface;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Refund;

final class RefundRepository implements RefundRepositoryInterface
{
    public function create(array $attributes): Refund
    {
        return Refund::create($attributes);
    }

    public function findByKey(string $key, int|string $merchantAccountId): Refund
    {
        return Refund::where('key', $key)
            ->where('merchant_account_id', $merchantAccountId)
            ->firstOrFail();
    }

    public function sumSucceededForPayment(int $paymentIntentId): int
    {
        return (int) Refund::where('payment_intent_id', $paymentIntentId)
            ->where('status', RefundStatus::Succeeded)
            ->sum('amount');
    }

    public function sumPendingAndSucceededForPayment(int $paymentIntentId): int
    {
        return (int) Refund::where('payment_intent_id', $paymentIntentId)
            ->whereIn('status', [RefundStatus::Succeeded, RefundStatus::Pending])
            ->sum('amount');
    }
}
