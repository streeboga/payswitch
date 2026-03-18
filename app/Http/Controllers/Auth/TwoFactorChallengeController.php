<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Services\TwoFactorAuthService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

#[Group('Authentication', description: 'User authentication', weight: 0)]
final class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthService $twoFactorAuthService,
    ) {}

    /**
     * Verify two-factor code
     *
     * Complete authentication with a TOTP code or recovery code.
     */
    #[Response(200, description: 'Two-factor verified')]
    #[Response(422, description: 'Invalid code')]
    public function store(TwoFactorChallengeRequest $request): JsonResponse
    {
        $user = $this->twoFactorAuthService->resolveUserFromSession(
            $request->session()->get('login.id'),
        );

        $this->twoFactorAuthService->verify(
            $user,
            $request->string('code')->toString() ?: null,
            $request->string('recovery_code')->toString() ?: null,
        );

        Auth::login($user);

        $request->session()->forget('login.id');
        $request->session()->regenerate();

        return response()->json($user);
    }
}
