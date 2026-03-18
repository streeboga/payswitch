<?php

declare(strict_types=1);

namespace App\Concerns;

use PragmaRX\Google2FA\Google2FA;

trait HasTwoFactorAuthentication
{
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    public function verifyTwoFactorCode(string $code): bool
    {
        $google2fa = app(Google2FA::class);

        return (bool) $google2fa->verifyKey(
            decrypt((string) $this->two_factor_secret),
            $code,
        );
    }

    public function verifyRecoveryCode(string $code): bool
    {
        $codes = json_decode(decrypt((string) $this->two_factor_recovery_codes), true);

        if (! in_array($code, $codes, true)) {
            return false;
        }

        $this->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode(
                array_values(array_diff($codes, [$code])),
            )),
        ])->save();

        return true;
    }
}
