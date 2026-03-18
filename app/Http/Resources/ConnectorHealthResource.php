<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class ConnectorHealthResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return $this->resource['key'];
    }

    public function toType(Request $request): string
    {
        return 'connector-health';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'connector_name' => $this->resource['connector_name'],
            'period' => $this->resource['period'],
            'total_attempts' => $this->resource['total_attempts'],
            'success_count' => $this->resource['success_count'],
            'error_count' => $this->resource['error_count'],
            'success_rate' => $this->resource['success_rate'],
            'error_rate' => $this->resource['error_rate'],
        ];
    }

    public function toLinks(Request $request): array
    {
        return [];
    }
}
