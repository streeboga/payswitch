<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class WebhookEventResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'webhook-events';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'event_type' => $this->event_type,
            'delivered' => $this->delivered,
            'delivery_attempts' => $this->delivery_attempts,
            'last_error' => $this->last_error,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/dashboard/webhook-events/{$this->key}",
        ];
    }
}
