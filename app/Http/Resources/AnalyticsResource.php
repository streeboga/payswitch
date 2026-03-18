<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AnalyticsResource extends JsonApiResource
{
    private string $resourceType;

    public function __construct(mixed $resource, string $type)
    {
        parent::__construct($resource);
        $this->resourceType = $type;
    }

    public function toType(Request $request): string
    {
        return $this->resourceType;
    }

    public function toId(Request $request): string
    {
        return '1';
    }

    public function toAttributes(Request $request): array
    {
        return $this->resource;
    }

    public function toLinks(Request $request): array
    {
        return [];
    }

    /**
     * Create a JSON:API list response from an array of items.
     */
    public static function jsonApiListFromArray(string $type, array $items, Request $request): JsonResponse
    {
        $data = array_map(fn (array $item, int $index) => [
            'type' => $type,
            'id' => (string) ($index + 1),
            'attributes' => $item,
        ], $items, array_keys($items));

        return response()->json(
            ['data' => $data],
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }
}
