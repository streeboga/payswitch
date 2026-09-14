<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreDashboardOrganizationRequest;
use App\Http\Requests\Dashboard\UpdateDashboardOrganizationRequest;
use App\Http\Resources\MerchantAccountResource;
use App\Http\Resources\OrganizationResource;
use App\Services\MerchantService;
use App\Services\UserRoleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Organizations', description: 'Organization management for the dashboard', weight: 19)]
final class DashboardOrganizationController extends Controller
{
    public function __construct(
        private readonly MerchantService $merchantService,
        private readonly UserRoleService $userRoleService,
    ) {}

    /**
     * List organizations
     *
     * Retrieve all organizations accessible to the current user.
     */
    #[Response(200, description: 'Organization list')]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?? abort(401);
        $organizations = $this->merchantService->listOrganizationsForUser($user->id);

        return OrganizationResource::jsonApiList($organizations, $request);
    }

    /**
     * Create organization
     *
     * Create a new organization. The creator becomes its admin.
     */
    #[Response(201, description: 'Organization created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreDashboardOrganizationRequest $request): JsonResponse
    {
        $user = $request->user() ?? abort(401);

        // Без роли создатель не увидит свою организацию: список и доступ
        // режутся по ролям пользователя.
        $org = DB::transaction(function () use ($request, $user) {
            $org = $this->merchantService->createOrganization($request->toDto());
            $this->userRoleService->assignRole([
                'user_id' => $user->id,
                'organization_id' => $org->id,
                'role' => UserRole::Admin->value,
            ]);

            return $org;
        });

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
    #[Response(403, description: 'No role in this organization')]
    #[Response(404, description: 'Organization not found')]
    public function show(string $orgKey, Request $request): JsonResponse
    {
        $org = $this->merchantService->findOrganization($orgKey);
        Gate::authorize('organization.view', [$org->id]);

        return (new OrganizationResource($org))->toResponse($request);
    }

    /**
     * Update organization
     *
     * Update an existing organization.
     */
    #[PathParameter('orgKey', description: 'Organization public key', example: 'org_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Organization updated')]
    #[Response(404, description: 'Organization not found')]
    #[Response(422, description: 'Validation error')]
    public function update(string $orgKey, UpdateDashboardOrganizationRequest $request): JsonResponse
    {
        $org = $this->merchantService->findOrganization($orgKey);
        Gate::authorize('organization.update', [$org->id]);

        $org = $this->merchantService->updateOrganization($orgKey, $request->toDto());

        return (new OrganizationResource($org))->toResponse($request);
    }

    /**
     * Delete organization
     *
     * Delete an organization and all its merchants, profiles, and related data.
     */
    #[PathParameter('orgKey', description: 'Organization public key', example: 'org_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Organization deleted')]
    #[Response(404, description: 'Organization not found')]
    public function destroy(string $orgKey): JsonResponse
    {
        $org = $this->merchantService->findOrganization($orgKey);
        Gate::authorize('organization.delete', [$org->id]);

        $this->merchantService->deleteOrganization($orgKey);

        return response()->json(null, 204);
    }

    /**
     * List merchants for organization
     *
     * Retrieve all merchants belonging to an organization.
     */
    #[PathParameter('orgKey', description: 'Organization public key')]
    #[Response(200, description: 'Merchant list')]
    #[Response(403, description: 'No role in this organization')]
    public function merchants(string $orgKey, Request $request): JsonResponse
    {
        $org = $this->merchantService->findOrganization($orgKey);
        Gate::authorize('organization.view', [$org->id]);

        $merchants = $this->merchantService->listMerchantsByOrganization($orgKey);

        return MerchantAccountResource::jsonApiList($merchants, $request);
    }
}
