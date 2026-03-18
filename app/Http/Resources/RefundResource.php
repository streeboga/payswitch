<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class RefundResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'refunds';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'payment_id' => $this->paymentIntent?->key,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'connector' => $this->connector,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toRelationships(Request $request): array
    {
        return [
            'payment' => fn () => new PaymentIntentResource($this->paymentIntent),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/refunds/{$this->key}",
        ];
    }
}
