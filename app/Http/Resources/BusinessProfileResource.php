<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class BusinessProfileResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'profiles';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'merchant_id' => $this->merchantAccount?->key,
            'webhook_url' => $this->webhook_url,
            'payment_response_hash_key' => $this->payment_response_hash_key,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/profiles/{$this->key}",
        ];
    }
}
