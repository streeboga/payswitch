<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Streeboga\PaymentData\Models\WebhookEvent;

final class WebhookEventQueryBuilder
{
    /** @var Builder<WebhookEvent> */
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
        $this->query->where('delivered', false)->where('delivery_attempts', 0);

        return $this;
    }

    public function delivered(): self
    {
        $this->query->where('delivered', true);

        return $this;
    }

    public function failed(): self
    {
        $this->query->where('delivered', false)->where('delivery_attempts', '>', 0);

        return $this;
    }

    public function withEventType(string $eventType): self
    {
        $this->query->where('event_type', $eventType);

        return $this;
    }

    public function orderByLatest(): self
    {
        $this->query->orderByDesc('created_at');

        return $this;
    }

    /** @return LengthAwarePaginator<int, WebhookEvent> */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }

    public function whereKey(string $key): self
    {
        $this->query->where('key', $key);

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

    /** @return Builder<WebhookEvent> */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
