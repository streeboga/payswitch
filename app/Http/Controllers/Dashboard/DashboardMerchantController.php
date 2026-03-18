<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreDashboardMerchantRequest;
use App\Http\Resources\MerchantAccountResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Merchants', description: 'Merchant account management for the dashboard', weight: 20)]
final class DashboardMerchantController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
    ) {}

    /**
     * List merchants
     *
     * Retrieve all merchant accounts accessible to the current user.
     */
    #[Response(200, description: 'Merchant list')]
    public function index(Request $request): JsonResponse
    {
        $merchants = $this->merchantService->listAllMerchants();

        return MerchantAccountResource::jsonApiList($merchants, $request);
    }

    /**
     * Get merchant
     *
     * Retrieve a single merchant account.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Merchant details')]
    #[Response(404, description: 'Merchant not found')]
    public function show(string $merchantKey, Request $request): JsonResponse
    {
        $merchant = $this->merchantService->findMerchant($merchantKey);

        return (new MerchantAccountResource($merchant))->toResponse($request);
    }

    /**
     * Create merchant
     *
     * Create a new merchant account under an organization.
     */
    #[Response(201, description: 'Merchant created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreDashboardMerchantRequest $request): JsonResponse
    {
        $merchant = $this->merchantService->createMerchantAccount($request->toDto());

        return (new MerchantAccountResource($merchant))
            ->withStatus(201)
            ->withHeader('Location', "/api/v1/dashboard/merchants/{$merchant->key}")
            ->toResponse($request);
    }
}
