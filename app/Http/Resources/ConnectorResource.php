<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class ConnectorResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'connectors';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'connector_name' => $this->connector_name,
            'connector_type' => $this->connector_type,
            'payment_methods_enabled' => $this->payment_methods_enabled,
            'test_mode' => $this->test_mode,
            'disabled' => $this->disabled,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/dashboard/connectors/{$this->key}",
        ];
    }
}
