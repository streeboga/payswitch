<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Streeboga\PaymentData\Models\PaymentIntent;

final class PaymentIntentQueryBuilder
{
    /** @var Builder<PaymentIntent> */
    private Builder $query;

    public function __construct()
    {
        $this->query = PaymentIntent::query();
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

    public function locked(): self
    {
        $this->query->lockForUpdate();

        return $this;
    }

    public function byId(int $id): self
    {
        $this->query->where('id', $id);

        return $this;
    }

    public function withStatus(string|\BackedEnum $status): self
    {
        $this->query->where('status', $status instanceof \BackedEnum ? $status->value : $status);

        return $this;
    }

    public function withCurrency(string $currency): self
    {
        $this->query->where('currency', $currency);

        return $this;
    }

    public function withConnector(string $connector): self
    {
        $this->query->where('connector', $connector);

        return $this;
    }

    public function withCaptureMethod(string $captureMethod): self
    {
        $this->query->where('capture_method', $captureMethod);

        return $this;
    }

    public function amountBetween(?int $min, ?int $max): self
    {
        if ($min !== null) {
            $this->query->where('amount', '>=', $min);
        }
        if ($max !== null) {
            $this->query->where('amount', '<=', $max);
        }

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
            $q->whereLike('key', "%{$escaped}%")
                ->orWhereLike('description', "%{$escaped}%")
                ->orWhereLike('error_message', "%{$escaped}%");
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

    /** @return LengthAwarePaginator<int, PaymentIntent> */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }

    public function latest(): self
    {
        $this->query->latest();

        return $this;
    }

    /** @return LazyCollection<int, PaymentIntent> */
    public function cursor(): LazyCollection
    {
        return $this->query->cursor();
    }

    public function firstOrFail(): PaymentIntent
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?PaymentIntent
    {
        return $this->query->first();
    }

    /** @return Builder<PaymentIntent> */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
