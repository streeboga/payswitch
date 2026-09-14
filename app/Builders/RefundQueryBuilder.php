<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Refund;

final class RefundQueryBuilder
{
    /** @var Builder<Refund> */
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

    /** Публичный ключ платежа — то, чем оперирует вызывающая сторона. */
    public function forPaymentKey(string $paymentKey): self
    {
        $this->query->whereHas('paymentIntent', fn (Builder $q) => $q->where('key', $paymentKey));

        return $this;
    }

    public function whereIdempotencyKey(string $idempotencyKey): self
    {
        $this->query->where('idempotency_key', $idempotencyKey);

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

    public function createdBetween(string $from, string $to): self
    {
        $this->query->whereBetween('created_at', [$from, $to]);

        return $this;
    }

    public function search(string $term): self
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);

        $this->query->where(function (Builder $q) use ($escaped) {
            $q->where('key', 'like', "%{$escaped}%")
                ->orWhere('reason', 'like', "%{$escaped}%")
                ->orWhere('error_message', 'like', "%{$escaped}%");
        });

        return $this;
    }

    public function sortBy(string $sort): self
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        $allowed = ['created_at', 'amount', 'status'];

        if (in_array($column, $allowed, true)) {
            $this->query->orderBy($column, $direction);
        }

        return $this;
    }

    /**
     * @param  array<int, string>|string  $relations
     */
    public function with(array|string $relations): self
    {
        $this->query->with($relations);

        return $this;
    }

    public function latest(): self
    {
        $this->query->latest();

        return $this;
    }

    /** @return LengthAwarePaginator<int, Refund> */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
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

    /** @return Builder<Refund> */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
