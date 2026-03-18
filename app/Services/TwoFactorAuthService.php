<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class TwoFactorAuthService
{
    /**
     * Resolve the user from the 2FA session.
     *
     * @throws ValidationException
     */
    public function resolveUserFromSession(?int $userId): User
    {
        if (! $userId) {
            throw ValidationException::withMessages([
                'code' => ['The two factor authentication session has expired.'],
            ]);
        }

        return User::findOrFail($userId);
    }

    /**
     * Verify a two-factor authentication attempt.
     *
     * @throws ValidationException
     */
    public function verify(User $user, ?string $code, ?string $recoveryCode): void
    {
        if ($code) {
            $this->verifyCode($user, $code);
        } elseif ($recoveryCode) {
            $this->verifyRecoveryCode($user, $recoveryCode);
        } else {
            throw ValidationException::withMessages([
                'code' => ['A two factor authentication code or recovery code is required.'],
            ]);
        }
    }

    /**
     * Verify a TOTP code.
     *
     * @throws ValidationException
     */
    public function verifyCode(User $user, string $code): void
    {
        if (! $user->verifyTwoFactorCode($code)) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ]);
        }
    }

    /**
     * Verify a recovery code.
     *
     * @throws ValidationException
     */
    public function verifyRecoveryCode(User $user, string $recoveryCode): void
    {
        if (! $user->verifyRecoveryCode($recoveryCode)) {
            throw ValidationException::withMessages([
                'recovery_code' => [__('The provided recovery code was invalid.')],
            ]);
        }
    }
}
