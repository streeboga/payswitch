<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Refund;

final class RefundQueryBuilder
{
    private Builder $query;

    public function __construct()
    {
        $this->query = Refund::query();
    }

    public static function make(): self
    {
        return new self;
    }

    public function forMerchant(int|string $merchantAccountId): self
    {
        $this->query->where('merchant_account_id', $merchantAccountId);

        return $this;
    }

    public function forPaymentIntent(int $paymentIntentId): self
    {
        $this->query->where('payment_intent_id', $paymentIntentId);

        return $this;
    }

    public function whereKey(string $key): self
    {
        $this->query->where('key', $key);

        return $this;
    }

    public function withStatus(string|RefundStatus $status): self
    {
        $this->query->where('status', $status instanceof RefundStatus ? $status->value : $status);

        return $this;
    }

    public function sumAmount(): int
    {
        return (int) $this->query->sum('amount');
    }

    public function firstOrFail(): Refund
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?Refund
    {
        return $this->query->first();
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
