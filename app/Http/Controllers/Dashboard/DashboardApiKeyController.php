<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\DataTransferObjects\Admin\CreateApiKeyData;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\ApiKey;

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
        $keys = ApiKey::where('merchant_account_id', $merchantId)
            ->orderByDesc('created_at')
            ->get();

        return ApiKeyResource::jsonApiList($keys, $request);
    }

    /**
     * Create API key
     *
     * Generate a new API key. The raw key is only shown once in the response.
     */
    #[Response(201, description: 'API key created with raw key')]
    #[Response(422, description: 'Validation error')]
    public function store(Request $request): JsonResponse
    {
        $merchantKey = $request->attributes->get('merchant_key');

        $validated = $request->validate([
            'data.attributes.name' => 'required|string|max:255',
        ]);

        $result = $this->merchantService->createApiKey(
            $merchantKey,
            CreateApiKeyData::from($validated['data']['attributes']),
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
    #[Response(204, description: 'API key revoked')]
    #[Response(404, description: 'API key not found')]
    public function destroy(string $keyId, Request $request): JsonResponse
    {
        $merchantKey = $request->attributes->get('merchant_key');
        $this->merchantService->revokeApiKey($merchantKey, $keyId);

        return response()->json(null, 204);
    }
}
