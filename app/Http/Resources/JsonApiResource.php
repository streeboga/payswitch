<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base JSON:API Resource.
 *
 * Implements JSON:API v1.1 envelope format with {data: {type, id, attributes, relationships, links}}.
 * Subclasses override toId(), toType(), toAttributes(), toRelationships(), toLinks().
 */
abstract class JsonApiResource extends JsonResource
{
    /**
     * Return the resource's public key as its JSON:API id.
     */
    public function toId(Request $request): string
    {
        return $this->key ?? (string) $this->id;
    }

    /**
     * Return the JSON:API type (plural, e.g. 'customers', 'payments').
     */
    abstract public function toType(Request $request): string;

    /**
     * Return the resource's attributes.
     * Subclasses MUST override this method.
     */
    public function toAttributes(Request $request): array
    {
        return [];
    }

    /**
     * Return the resource's relationships as lazy closures.
     *
     * @return array<string, \Closure>
     */
    public function toRelationships(Request $request): array
    {
        return [];
    }

    /**
     * Return the resource's links.
     *
     * @return array<string, string>
     */
    public function toLinks(Request $request): array
    {
        return [
            'self' => $request->url(),
        ];
    }

    /**
     * Transform the resource into a JSON:API array.
     */
    public function toArray(Request $request): array
    {
        $data = [
            'type' => $this->toType($request),
            'id' => $this->toId($request),
            'attributes' => $this->toAttributes($request),
        ];

        $resolved = [];
        foreach ($this->toRelationships($request) as $name => $resolver) {
            $related = is_callable($resolver) ? $resolver() : $resolver;
            if ($related instanceof JsonApiResource) {
                $resolved[$name] = [
                    'data' => [
                        'type' => $related->toType($request),
                        'id' => $related->toId($request),
                    ],
                ];
            } elseif ($related instanceof AnonymousResourceCollection) {
                $resolved[$name] = [
                    'data' => $related->map(fn ($r) => [
                        'type' => $r->toType($request),
                        'id' => $r->toId($request),
                    ])->toArray(),
                ];
            }
        }
        if (! empty($resolved)) {
            $data['relationships'] = $resolved;
        }

        $links = $this->toLinks($request);
        if (! empty($links)) {
            $data['links'] = $links;
        }

        return $data;
    }

    /**
     * Create a JSON response with proper JSON:API envelope for single resource.
     */
    public function toResponse($request): JsonResponse
    {
        return response()->json(
            ['data' => $this->toArray($request)],
            $this->statusCode,
            array_merge(
                ['Content-Type' => 'application/vnd.api+json'],
                $this->additionalHeaders,
            ),
        );
    }

    /**
     * Create a paginated JSON:API collection response.
     *
     * @param  LengthAwarePaginator  $paginator
     */
    public static function jsonApiCollection($paginator, Request $request): JsonResponse
    {
        $data = collect($paginator->items())->map(
            fn ($item) => (new static($item))->toArray($request)
        )->toArray();

        return response()->json([
            'data' => $data,
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    /**
     * Create a simple collection response (no pagination).
     */
    public static function jsonApiList($items, Request $request): JsonResponse
    {
        $data = collect($items)->map(
            fn ($item) => (new static($item))->toArray($request)
        )->values()->toArray();

        return response()->json(
            ['data' => $data],
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    // --- Internal helpers ---

    private int $statusCode = 200;

    private array $additionalHeaders = [];

    public function withStatus(int $code): static
    {
        $this->statusCode = $code;

        return $this;
    }

    public function withHeader(string $key, string $value): static
    {
        $this->additionalHeaders[$key] = $value;

        return $this;
    }
}
