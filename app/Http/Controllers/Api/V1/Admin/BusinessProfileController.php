<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreBusinessProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;

final class BusinessProfileController extends Controller
{
    use JsonApiResponse;

    public function store(StoreBusinessProfileRequest $request): JsonResponse
    {
        $attrs = $request->validatedAttributes();

        $merchant = MerchantAccount::where('key', $attrs['merchant_id'])->firstOrFail();

        $profile = BusinessProfile::create([
            'merchant_account_id' => $merchant->id,
            'webhook_url' => $attrs['webhook_url'] ?? null,
        ]);

        return $this->jsonApiResource(
            model: $profile,
            type: 'profiles',
            attributes: [
                'merchant_id' => $merchant->key,
                'webhook_url' => $profile->webhook_url,
                'payment_response_hash_key' => $profile->payment_response_hash_key,
                'created_at' => $profile->created_at->toIso8601String(),
            ],
            status: 201,
            headers: ['Location' => url("/api/v1/profiles/{$profile->key}")],
        );
    }

    public function show(string $profileKey): JsonResponse
    {
        $profile = BusinessProfile::where('key', $profileKey)->firstOrFail();

        return $this->jsonApiResource(
            model: $profile,
            type: 'profiles',
            attributes: [
                'merchant_id' => $profile->merchantAccount->key,
                'webhook_url' => $profile->webhook_url,
                'payment_response_hash_key' => $profile->payment_response_hash_key,
                'created_at' => $profile->created_at->toIso8601String(),
            ],
        );
    }
}
