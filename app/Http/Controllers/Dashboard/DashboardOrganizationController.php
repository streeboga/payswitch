<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreDashboardOrganizationRequest;
use App\Http\Resources\MerchantAccountResource;
use App\Http\Resources\OrganizationResource;
use App\Services\MerchantService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Organizations', description: 'Organization management for the dashboard', weight: 19)]
final class DashboardOrganizationController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
    ) {}

    /**
     * List organizations
     *
     * Retrieve all organizations accessible to the current user.
     */
    #[Response(200, description: 'Organization list')]
    public function index(Request $request): JsonResponse
    {
        $organizations = $this->merchantService->listOrganizations();

        return OrganizationResource::jsonApiList($organizations, $request);
    }

    /**
     * Create organization
     *
     * Create a new organization.
     */
    #[Response(201, description: 'Organization created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreDashboardOrganizationRequest $request): JsonResponse
    {
        $org = $this->merchantService->createOrganization($request->toDto());

        return (new OrganizationResource($org))
            ->withStatus(201)
            ->withHeader('Location', "/api/v1/dashboard/organizations/{$org->key}")
            ->toResponse($request);
    }

    /**
     * Get organization
     *
     * Retrieve an organization with its merchants.
     */
    #[PathParameter('orgKey', description: 'Organization public key', example: 'org_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Organization details')]
    #[Response(404, description: 'Organization not found')]
    public function show(string $orgKey, Request $request): JsonResponse
    {
        $org = $this->merchantService->findOrganization($orgKey);

        return (new OrganizationResource($org))->toResponse($request);
    }

    /**
     * List merchants for organization
     *
     * Retrieve all merchants belonging to an organization.
     */
    #[PathParameter('orgKey', description: 'Organization public key')]
    #[Response(200, description: 'Merchant list')]
    public function merchants(string $orgKey, Request $request): JsonResponse
    {
        $merchants = $this->merchantService->listMerchantsByOrganization($orgKey);

        return MerchantAccountResource::jsonApiList($merchants, $request);
    }
}
