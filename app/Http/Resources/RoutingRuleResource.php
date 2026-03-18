<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class RoutingRuleResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'routing-rules';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'rules' => $this->rules,
            'active' => $this->active,
            'priority' => $this->priority,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/dashboard/routing-rules/{$this->key}",
        ];
    }
}
