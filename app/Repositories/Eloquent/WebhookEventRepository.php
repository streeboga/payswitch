<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Streeboga\PaymentData\Models\WebhookEvent;

final class WebhookEventRepository implements WebhookEventRepositoryInterface
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
        return WebhookEvent::where('merchant_account_id', $merchantId)
            ->where('key', $key)
            ->firstOrFail();
    }

    public function paginateForMerchant(int|string $merchantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = WebhookEvent::where('merchant_account_id', $merchantId);

        if (($filters['status'] ?? null) === 'delivered') {
            $query->where('delivered', true);
        } elseif (($filters['status'] ?? null) === 'failed') {
            $query->where('delivered', false)->where('delivery_attempts', '>', 0);
        } elseif (($filters['status'] ?? null) === 'pending') {
            $query->where('delivered', false)->where('delivery_attempts', 0);
        }

        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        return $query->orderByDesc('created_at')->paginate(min($perPage, 100));
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
