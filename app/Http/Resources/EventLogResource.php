<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class EventLogResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return $this->event_id;
    }

    public function toType(Request $request): string
    {
        return 'event-logs';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'event_type' => $this->type,
            'action' => $this->action,
            'resource_id' => $this->resource_id,
            'status' => $this->status,
            'detail' => $this->detail,
            'created_at' => $this->created_at,
        ];
    }

    public function toLinks(Request $request): array
    {
        return [];
    }
}
