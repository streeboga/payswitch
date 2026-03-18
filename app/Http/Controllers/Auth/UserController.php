<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Authentication', description: 'User authentication', weight: 0)]
final class UserController extends Controller
{
    /**
     * Get current user
     *
     * Returns the authenticated user's profile.
     */
    #[Response(200, description: 'Current user')]
    #[Response(401, description: 'Unauthenticated')]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles.organization');

        $roles = $user->roles->map(fn ($r) => [
            'organization_id' => $r->organization?->key,
            'role' => $r->role->value,
        ]);

        return response()->json([
            ...$user->toArray(),
            'roles' => $roles,
        ]);
    }
}
