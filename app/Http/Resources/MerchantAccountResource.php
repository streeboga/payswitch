<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class MerchantAccountResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'merchants';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'publishable_key' => $this->publishable_key,
            'organization_id' => $this->organization?->key,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toRelationships(Request $request): array
    {
        return [
            'organization' => fn () => new OrganizationResource($this->organization),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/merchants/{$this->key}",
        ];
    }
}
