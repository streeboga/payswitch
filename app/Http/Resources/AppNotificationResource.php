<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class AppNotificationResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'notifications';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'content' => $this->content,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
