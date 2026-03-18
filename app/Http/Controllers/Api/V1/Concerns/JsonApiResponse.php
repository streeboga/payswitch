<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

trait JsonApiResponse
{
    protected function jsonApiResource(Model $model, string $type, array $attributes, int $status = 200, array $headers = []): JsonResponse
    {
        $data = [
            'data' => [
                'type' => $type,
                'id' => $model->key ?? (string) $model->id,
                'attributes' => $attributes,
                'links' => [
                    'self' => request()->url(),
                ],
            ],
        ];

        return response()->json($data, $status)->withHeaders($headers);
    }

    protected function jsonApiCollection(iterable $models, string $type, callable $attributeMapper): JsonResponse
    {
        $data = collect($models)->map(fn (Model $model) => [
            'type' => $type,
            'id' => $model->key ?? (string) $model->id,
            'attributes' => $attributeMapper($model),
        ])->values()->toArray();

        return response()->json(['data' => $data]);
    }

    protected function jsonApiNoContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
