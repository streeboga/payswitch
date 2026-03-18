<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreApiKeyRequest;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Support\IdGenerator;

#[Group(name: 'Admin > API Keys', weight: 13)]
final class ApiKeyController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    /**
     * Create an API key.
     *
     * Generates a new API key for the specified merchant account. The full API key value
     * is returned only once in this response and cannot be retrieved again. Store it securely.
     * The key prefix is retained for identification purposes.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     */
    public function store(StoreApiKeyRequest $request, string $merchantKey): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $attrs = $request->validatedAttributes();

        $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));

        $apiKey = $this->merchantRepository->createApiKey([
            'merchant_account_id' => $merchant->id,
            'key_hash' => bcrypt($rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => $attrs['name'] ?? null,
        ]);

        return $this->jsonApiResource(
            model: $apiKey,
            type: 'api-keys',
            attributes: [
                'name' => $apiKey->name,
                'key_prefix' => $apiKey->key_prefix,
                'api_key' => $rawKey,
                'created_at' => $apiKey->created_at->toIso8601String(),
            ],
            status: 201,
            headers: ['Location' => url("/api/v1/merchants/{$merchantKey}/api-keys/{$apiKey->id}")],
        );
    }

    /**
     * Revoke an API key.
     *
     * Revokes an existing API key for the specified merchant account. Once revoked,
     * the key can no longer be used to authenticate API requests.
     *
     * @pathParam merchantKey string required The unique key of the merchant account. Example: mer_1a2b3c4d5e
     * @pathParam keyId string required The ID of the API key to revoke. Example: 1
     */
    public function destroy(string $merchantKey, string $keyId): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $apiKey = $merchant->apiKeys()->findOrFail($keyId);
        $apiKey->revoke();

        return $this->jsonApiNoContent();
    }
}
