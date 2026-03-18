<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\WebhookEventRepositoryInterface;
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
