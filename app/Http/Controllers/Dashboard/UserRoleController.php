<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreUserRoleRequest;
use App\Http\Requests\Dashboard\UpdateUserRoleRequest;
use App\Http\Resources\UserRoleResource;
use App\Services\UserRoleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Users & RBAC', description: 'User and role management', weight: 25)]
final class UserRoleController extends Controller
{
    public function __construct(
        private readonly UserRoleService $userRoleService,
    ) {}

    /**
     * List users with roles
     *
     * Retrieve users and their roles for an organization.
     */
    #[Response(200, description: 'User list with roles')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('user-role.viewAny', [$merchantId]);

        $roles = $this->userRoleService->getRolesForMerchant($merchantId);

        return UserRoleResource::jsonApiList($roles, $request);
    }

    /**
     * Assign role to user
     *
     * Assign a role to a user within the organization.
     */
    #[Response(201, description: 'Role assigned')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreUserRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $role = $this->userRoleService->assignRole($validated);

        return (new UserRoleResource($role))->withStatus(201)->toResponse($request);
    }

    /**
     * Update user role
     *
     * Change a user's role.
     */
    #[PathParameter('roleId', description: 'User role ID')]
    #[Response(200, description: 'Role updated')]
    public function update(string $roleId, UpdateUserRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $role = $this->userRoleService->updateRole($roleId, $validated['role']);

        return (new UserRoleResource($role))->toResponse($request);
    }

    /**
     * Revoke user access
     *
     * Remove a user's role from the organization.
     */
    #[PathParameter('roleId', description: 'User role ID')]
    #[Response(204, description: 'Role revoked')]
    public function destroy(string $roleId): JsonResponse
    {
        $this->userRoleService->deleteRole($roleId);

        return response()->json(null, 204);
    }
}
