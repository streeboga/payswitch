<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Admin\StoreBusinessProfileRequest;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Business Profiles', weight: 12)]
final class BusinessProfileController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    /**
     * Create a business profile.
     *
     * Creates a new business profile for a merchant account. Business profiles allow
     * merchants to configure separate webhook URLs and payment response hash keys
     * for different business lines or product verticals.
     */
    public function store(StoreBusinessProfileRequest $request): JsonResponse
    {
        $attrs = $request->validatedAttributes();

        $merchant = $this->merchantRepository->findMerchantByKey($attrs['merchant_id']);

        $profile = $this->merchantRepository->createBusinessProfile([
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

    /**
     * Get a business profile.
     *
     * Retrieves the details of a business profile including its webhook URL
     * and payment response hash key used for signature verification.
     *
     * @pathParam profileKey string required The unique key of the business profile. Example: bpr_1a2b3c4d5e
     */
    public function show(string $profileKey): JsonResponse
    {
        $profile = $this->merchantRepository->findProfileByKey($profileKey);

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
