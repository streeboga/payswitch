<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessProfileResource;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\BusinessProfile;

#[Group('Dashboard Business Profiles', description: 'Business profile management for the dashboard', weight: 20)]
final class DashboardBusinessProfileController extends Controller
{
    public function __construct(
        private readonly MerchantRepositoryInterface $merchantRepository,
    ) {}

    /**
     * List business profiles
     *
     * Retrieve all business profiles for the current merchant.
     */
    #[Response(200, description: 'Profile list')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $profiles = BusinessProfile::where('merchant_account_id', $merchantId)->get();

        return BusinessProfileResource::jsonApiList($profiles, $request);
    }

    /**
     * List profiles by merchant key
     *
     * Retrieve all business profiles for a specific merchant (used by context switcher).
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Profile list')]
    #[Response(404, description: 'Merchant not found')]
    public function indexByMerchant(string $merchantKey, Request $request): JsonResponse
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $profiles = BusinessProfile::where('merchant_account_id', $merchant->id)->get();

        return BusinessProfileResource::jsonApiList($profiles, $request);
    }

    /**
     * Get business profile
     *
     * Retrieve a single business profile.
     */
    #[PathParameter('profileKey', description: 'Business profile public key', example: 'bp_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Profile details')]
    #[Response(404, description: 'Profile not found')]
    public function show(string $profileKey, Request $request): JsonResponse
    {
        $profile = $this->merchantRepository->findProfileByKey($profileKey);

        return (new BusinessProfileResource($profile))->toResponse($request);
    }

    /**
     * Create business profile
     *
     * Create a new business profile for the current merchant.
     */
    #[Response(201, description: 'Profile created')]
    #[Response(422, description: 'Validation error')]
    public function store(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $validated = $request->validate([
            'data.attributes.webhook_url' => 'sometimes|url|max:2048',
        ]);

        $profile = $this->merchantRepository->createBusinessProfile([
            'merchant_account_id' => $merchantId,
            'webhook_url' => $validated['data']['attributes']['webhook_url'] ?? null,
        ]);

        return (new BusinessProfileResource($profile))
            ->withStatus(201)
            ->withHeader('Location', "/api/v1/dashboard/profiles/{$profile->key}")
            ->toResponse($request);
    }

    /**
     * Update business profile
     *
     * Update business profile webhook URL.
     */
    #[PathParameter('profileKey', description: 'Business profile public key')]
    #[Response(200, description: 'Profile updated')]
    #[Response(404, description: 'Profile not found')]
    public function update(string $profileKey, Request $request): JsonResponse
    {
        $profile = $this->merchantRepository->findProfileByKey($profileKey);

        $validated = $request->validate([
            'data.attributes.webhook_url' => 'sometimes|nullable|url|max:2048',
        ]);

        $profile->update($validated['data']['attributes'] ?? []);

        return (new BusinessProfileResource($profile->fresh()))->toResponse($request);
    }
}
