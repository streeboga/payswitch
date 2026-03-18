<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > API Keys', description: 'API key generation and revocation', weight: 13)]
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
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(201, description: 'API key created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreApiKeyRequest $request, string $merchantKey): JsonResponse
    {
        $result = $this->merchantService->createApiKey($merchantKey, $request->toDto());

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
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[PathParameter('keyId', description: 'API key identifier')]
    #[Response(204, description: 'API key revoked')]
    public function destroy(string $merchantKey, string $keyId): JsonResponse
    {
        $this->merchantService->revokeApiKey($merchantKey, $keyId);

        return response()->json(null, 204);
    }
}
