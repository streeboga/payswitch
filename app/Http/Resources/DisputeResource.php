<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class DisputeResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'disputes';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'payment_id' => $this->paymentIntent?->key,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'reason_code' => $this->reason_code,
            'reason_description' => $this->reason_description,
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
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
            'self' => "/api/v1/dashboard/disputes/{$this->key}",
        ];
    }
}
