<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class OrganizationResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'organizations';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'merchants_count' => $this->merchantAccounts_count ?? 0,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/organizations/{$this->key}",
        ];
    }
}
