<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreDashboardApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard API Keys', description: 'API key management for the dashboard', weight: 15)]
final class DashboardApiKeyController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
    ) {}

    /**
     * List API keys
     *
     * Retrieve all API keys for the current merchant.
     */
    #[Response(200, description: 'API key list')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('api-key.viewAny', [$merchantId]);

        return ApiKeyResource::jsonApiList(
            $this->merchantService->listApiKeys($merchantId),
            $request,
        );
    }

    /**
     * Create API key
     *
     * Generate a new API key. The raw key is only shown once in the response.
     */
    #[Response(201, description: 'API key created with raw key')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreDashboardApiKeyRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('api-key.create', [$merchantId]);
        $merchantKey = $request->attributes->get('merchant_key');

        $result = $this->merchantService->createApiKey(
            $merchantKey,
            $request->toDto(),
        );

        return (new ApiKeyResource($result['apiKey']))
            ->withStatus(201)
            ->withHeader('X-Api-Key', $result['rawKey'])
            ->toResponse($request);
    }

    /**
     * Revoke API key
     *
     * Revoke an existing API key.
     */
    #[PathParameter('keyId', description: 'API key numeric ID')]
    #[Response(204, description: 'API key revoked')]
    #[Response(404, description: 'API key not found')]
    public function destroy(string $keyId, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('api-key.delete', [$merchantId]);
        $merchantKey = $request->attributes->get('merchant_key');
        $this->merchantService->revokeApiKey($merchantKey, $keyId);

        return response()->json(null, 204);
    }
}
