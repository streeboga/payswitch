<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Authentication', description: 'User authentication', weight: 0)]
final class LoginController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Authenticate user
     *
     * Authenticate with email and password. Returns user data or signals 2FA required.
     */
    #[Response(200, description: 'Login successful')]
    #[Response(422, description: 'Invalid credentials')]
    public function store(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $result = $this->authService->authenticate(
            $request,
            $request->input('email'),
            $request->input('password'),
            $request->boolean('remember'),
        );

        if ($result['two_factor']) {
            return response()->json(['two_factor' => true]);
        }

        return response()->json($result['user']);
    }

    /**
     * Logout
     *
     * Invalidate the current session.
     */
    #[Response(204, description: 'Logged out')]
    public function destroy(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return response()->json(null, 204);
    }
}
