<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Builders\WebhookEventQueryBuilder;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\WebhookEvent;

final readonly class WebhookEventRepository implements WebhookEventRepositoryInterface
{
    public function create(array $attributes): WebhookEvent
    {
        return WebhookEvent::create($attributes);
    }

    public function findById(int $id): ?WebhookEvent
    {
        return WebhookEvent::find($id);
    }

    public function findByKeyForMerchant(string $key, int|string $merchantId): WebhookEvent
    {
        return WebhookEventQueryBuilder::make()
            ->forMerchant($merchantId)
            ->whereKey($key)
            ->firstOrFail();
    }

    public function paginateForMerchant(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $builder = WebhookEventQueryBuilder::make()
            ->forMerchant($merchantId);

        if (($filters['status'] ?? null) === 'delivered') {
            $builder->delivered();
        } elseif (($filters['status'] ?? null) === 'failed') {
            $builder->failed();
        } elseif (($filters['status'] ?? null) === 'pending') {
            $builder->pending();
        }

        if (! empty($filters['event_type'])) {
            $builder->withEventType($filters['event_type']);
        }

        return $builder->orderByLatest()->paginate(min($perPage, 100));
    }

    public function markDelivered(WebhookEvent $event, int $attempts): void
    {
        $event->update([
            'delivered' => true,
            'delivery_attempts' => $attempts,
        ]);
    }

    public function markFailed(WebhookEvent $event, int $attempts, string $error): void
    {
        $event->update([
            'delivery_attempts' => $attempts,
            'last_error' => $error,
        ]);
    }
}
