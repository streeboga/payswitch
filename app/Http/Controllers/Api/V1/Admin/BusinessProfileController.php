<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreBusinessProfileRequest;
use App\Http\Requests\Api\Admin\UpdateBusinessProfileRequest;
use App\Http\Resources\BusinessProfileResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Business Profiles', description: 'Business profile management', weight: 12)]
final class BusinessProfileController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
    ) {}

    /**
     * Create a business profile.
     *
     * Creates a new business profile for a merchant account.
     */
    #[Response(201, description: 'Business profile created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreBusinessProfileRequest $request): JsonResponse
    {
        $profile = $this->merchantService->createBusinessProfile($request->toDto());

        return (new BusinessProfileResource($profile))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/profiles/{$profile->key}"))
            ->toResponse($request);
    }

    /**
     * Get a business profile.
     *
     * Retrieves the details of a business profile.
     */
    #[PathParameter('profileKey', description: 'Business profile public key', example: 'bp_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Business profile details')]
    #[Response(404, description: 'Profile not found')]
    public function show(string $profileKey, Request $request): JsonResponse
    {
        $profile = $this->merchantService->findProfile($profileKey);

        return (new BusinessProfileResource($profile))->toResponse($request);
    }

    /**
     * Get the merchant's business profile.
     *
     * Addressed by merchant key, like the update below. Read it before setting
     * a webhook address: a merchant may serve several consumers and the profile
     * holds only one address.
     */
    #[PathParameter('merchantKey', description: 'Merchant account public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Business profile details')]
    #[Response(404, description: 'Merchant or profile not found')]
    public function showByMerchant(string $merchantKey, Request $request): JsonResponse
    {
        $profile = $this->merchantService->findProfileByMerchant($merchantKey);

        return (new BusinessProfileResource($profile))->toResponse($request);
    }

    /**
     * Update the merchant's business profile.
     *
     * Sets the webhook address the merchant's payment events are delivered to.
     * Addressed by merchant key: whoever created the merchant has that key,
     * while the profile key is handed out only by the call that created it.
     *
     * The response carries `payment_response_hash_key` — the key every
     * delivery to this address is signed with.
     */
    #[PathParameter('merchantKey', description: 'Merchant account public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Business profile updated')]
    #[Response(404, description: 'Merchant or profile not found')]
    #[Response(422, description: 'Validation error')]
    public function updateByMerchant(string $merchantKey, UpdateBusinessProfileRequest $request): JsonResponse
    {
        $profile = $this->merchantService->updateProfileByMerchant($merchantKey, $request->validated());

        return (new BusinessProfileResource($profile))->toResponse($request);
    }
}
