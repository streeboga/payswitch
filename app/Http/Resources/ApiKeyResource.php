<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\ApiKey;

/**
 * @mixin ApiKey
 */
final class ApiKeyResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'api-keys';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        $attrs = [
            'name' => $this->name,
            'type' => $this->type->value,
            'key_prefix' => $this->key_prefix,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];

        // Plain API key is only available on creation (passed via additional data)
        if ($this->additional['api_key'] ?? null) {
            $attrs['api_key'] = $this->additional['api_key'];
        }

        return $attrs;
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/dashboard/api-keys/{$this->id}",
        ];
    }
}
