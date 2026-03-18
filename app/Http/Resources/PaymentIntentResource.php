<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class PaymentIntentResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'payments';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'amount' => $this->amount,
            'net_amount' => $this->net_amount,
            'amount_capturable' => $this->amount_capturable,
            'amount_received' => $this->amount_received,
            'currency' => $this->currency,
            'client_secret' => $this->client_secret,
            'capture_method' => $this->capture_method->value,
            'authentication_type' => $this->authentication_type->value,
            'customer_id' => $this->customer_id,
            'description' => $this->description,
            'return_url' => $this->return_url,
            'metadata' => $this->metadata,
            'connector' => $this->connector,
            'attempt_count' => $this->attempt_count,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'cancellation_reason' => $this->cancellation_reason,
            'session_expiry' => $this->session_expiry,
            'created_at' => $this->created_at->toIso8601String(),
            'expires_on' => $this->expires_on?->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/payments/{$this->key}",
        ];
    }
}
