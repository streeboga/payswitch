<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreBusinessProfileRequest;
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
}
