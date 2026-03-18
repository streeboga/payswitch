<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Dashboard Users & RBAC', description: 'User and role management', weight: 25)]
final class UserRoleController extends Controller
{
    /**
     * List users with roles
     *
     * Retrieve users and their roles for an organization.
     */
    #[Response(200, description: 'User list with roles')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $roles = \App\Models\UserRole::with('user')
            ->whereHas('organization', function ($q) use ($merchantId) {
                $q->whereHas('merchantAccounts', function ($q2) use ($merchantId) {
                    $q2->where('id', $merchantId);
                });
            })
            ->get();

        $items = $roles->map(fn ($role) => [
            'type' => 'user-roles',
            'id' => (string) $role->id,
            'attributes' => [
                'user_id' => $role->user_id,
                'user_name' => $role->user?->name,
                'user_email' => $role->user?->email,
                'role' => $role->role->value,
                'created_at' => $role->created_at->toIso8601String(),
            ],
        ])->toArray();

        return response()->json(
            ['data' => $items],
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    /**
     * Assign role to user
     *
     * Assign a role to a user within the organization.
     */
    #[Response(201, description: 'Role assigned')]
    #[Response(422, description: 'Validation error')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data.attributes.user_id' => 'required|exists:users,id',
            'data.attributes.organization_id' => 'required|exists:organizations,id',
            'data.attributes.role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $attrs = $validated['data']['attributes'];

        $role = \App\Models\UserRole::updateOrCreate(
            ['user_id' => $attrs['user_id'], 'organization_id' => $attrs['organization_id']],
            ['role' => $attrs['role']],
        );

        return response()->json([
            'data' => [
                'type' => 'user-roles',
                'id' => (string) $role->id,
                'attributes' => [
                    'user_id' => $role->user_id,
                    'role' => $role->role->value,
                    'created_at' => $role->created_at->toIso8601String(),
                ],
            ],
        ], 201, ['Content-Type' => 'application/vnd.api+json']);
    }

    /**
     * Update user role
     *
     * Change a user's role.
     */
    #[PathParameter('roleId', description: 'User role ID')]
    #[Response(200, description: 'Role updated')]
    public function update(string $roleId, Request $request): JsonResponse
    {
        $role = \App\Models\UserRole::findOrFail($roleId);

        $validated = $request->validate([
            'data.attributes.role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $role->update(['role' => $validated['data']['attributes']['role']]);

        return response()->json([
            'data' => [
                'type' => 'user-roles',
                'id' => (string) $role->id,
                'attributes' => ['role' => $role->role->value],
            ],
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
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
        $role = \App\Models\UserRole::findOrFail($roleId);
        $role->delete();

        return response()->json(null, 204);
    }
}
