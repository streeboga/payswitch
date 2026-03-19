<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SavedFilter;
use Illuminate\Http\Request;

/**
 * @mixin SavedFilter
 */
final class SavedFilterResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return (string) $this->id;
    }

    public function toType(Request $request): string
    {
        return 'saved-filters';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'table_name' => $this->table_name,
            'name' => $this->name,
            'filters' => $this->filters,
            'is_preset' => $this->is_preset,
            'sort_order' => $this->sort_order,
        ];
    }

    public function toLinks(Request $request): array
    {
        return [];
    }
}
