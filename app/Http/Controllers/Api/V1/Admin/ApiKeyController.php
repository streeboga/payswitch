<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > API Keys', weight: 13)]
final class ApiKeyController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
    ) {}

    /**
     * Create an API key.
     *
     * Generates a new API key for the specified merchant account. The full key value
     * is returned only once in this response and cannot be retrieved again.
     */
    public function store(StoreApiKeyRequest $request, string $merchantKey): JsonResponse
    {
        $attrs = $request->validatedAttributes();
        $result = $this->merchantService->createApiKey($merchantKey, $attrs['name'] ?? null);

        return (new ApiKeyResource($result['apiKey']))
            ->additional(['api_key' => $result['rawKey']])
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/merchants/{$merchantKey}/api-keys/{$result['apiKey']->id}"))
            ->toResponse($request);
    }

    /**
     * Revoke an API key.
     *
     * Revokes an existing API key. Once revoked, it can no longer authenticate requests.
     */
    public function destroy(string $merchantKey, string $keyId): JsonResponse
    {
        $this->merchantService->revokeApiKey($merchantKey, $keyId);

        return response()->json(null, 204);
    }
}
