<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Streeboga\PaymentData\Models\WebhookEvent;

final class WebhookEventQueryBuilder
{
    private Builder $query;

    public function __construct()
    {
        $this->query = WebhookEvent::query();
    }

    public static function make(): self
    {
        return new self;
    }

    public function whereId(int $id): self
    {
        $this->query->where('id', $id);

        return $this;
    }

    public function forMerchant(int|string $merchantAccountId): self
    {
        $this->query->where('merchant_account_id', $merchantAccountId);

        return $this;
    }

    public function pending(): self
    {
        $this->query->where('status', 'pending');

        return $this;
    }

    public function firstOrFail(): WebhookEvent
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?WebhookEvent
    {
        return $this->query->first();
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
