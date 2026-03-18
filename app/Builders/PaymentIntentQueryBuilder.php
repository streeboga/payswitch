<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Streeboga\PaymentData\Models\PaymentIntent;

final class PaymentIntentQueryBuilder
{
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

    public function byKey(string $key): self
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

    public function withStatus(string $status): self
    {
        $this->query->where('status', $status);

        return $this;
    }

    public function latest(): self
    {
        $this->query->latest();

        return $this;
    }

    public function firstOrFail(): PaymentIntent
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?PaymentIntent
    {
        return $this->query->first();
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
