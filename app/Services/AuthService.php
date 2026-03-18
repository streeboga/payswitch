<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class AuthService
{
    /**
     * Attempt to authenticate a user.
     *
     * @return array{user: User, two_factor: bool}
     *
     * @throws ValidationException
     */
    public function authenticate(Request $request, string $email, string $password, bool $remember): array
    {
        if (! Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            RateLimiter::hit($request->throttleKey());

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        RateLimiter::clear($request->throttleKey());

        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            Auth::logout();
            $request->session()->put('login.id', $user->id);

            return ['user' => $user, 'two_factor' => true];
        }

        $request->session()->regenerate();

        return ['user' => $user, 'two_factor' => false];
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
