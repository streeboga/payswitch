<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreApiKeyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Support\IdGenerator;

final class ApiKeyController extends Controller
{
    use JsonApiResponse;

    public function store(StoreApiKeyRequest $request, string $merchantKey): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();
        $attrs = $request->validatedAttributes();

        $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));

        $apiKey = ApiKey::create([
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

    public function destroy(string $merchantKey, string $keyId): JsonResponse
    {
        $merchant = MerchantAccount::where('key', $merchantKey)->firstOrFail();

        $apiKey = $merchant->apiKeys()->findOrFail($keyId);
        $apiKey->revoke();

        return $this->jsonApiNoContent();
    }
}
