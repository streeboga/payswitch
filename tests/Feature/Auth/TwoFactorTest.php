<?php

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new TwoFactorAuthService;
});

// --- resolveUserFromSession ---

test('resolveUserFromSession returns user when valid id provided', function () {
    $user = User::factory()->create();

    $resolved = $this->service->resolveUserFromSession($user->id);

    expect($resolved->id)->toBe($user->id);
});

test('resolveUserFromSession throws when null id provided', function () {
    $this->service->resolveUserFromSession(null);
})->throws(ValidationException::class);

test('resolveUserFromSession throws model not found for non-existent id', function () {
    $this->service->resolveUserFromSession(99999);
})->throws(ModelNotFoundException::class);

// --- verifyCode ---

test('verifyCode succeeds with valid TOTP code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $mock = Mockery::mock(Google2FA::class);
    $mock->shouldReceive('verifyKey')->once()->andReturn(true);
    $this->app->instance(Google2FA::class, $mock);

    $this->service->verifyCode($user, '123456');

    // No exception means success
    expect(true)->toBeTrue();
});

test('verifyCode throws with invalid TOTP code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $mock = Mockery::mock(Google2FA::class);
    $mock->shouldReceive('verifyKey')->once()->andReturn(false);
    $this->app->instance(Google2FA::class, $mock);

    $this->service->verifyCode($user, '000000');
})->throws(ValidationException::class);

// --- verifyRecoveryCode ---

test('verifyRecoveryCode succeeds with valid recovery code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->service->verifyRecoveryCode($user, 'recovery-code-1');

    // Recovery code should be consumed (removed from the list)
    $user->refresh();
    $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
    expect($codes)->not->toContain('recovery-code-1');
});

test('verifyRecoveryCode throws with invalid recovery code', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->service->verifyRecoveryCode($user, 'wrong-code');
})->throws(ValidationException::class);

test('used recovery code cannot be reused', function () {
    $user = User::factory()->withTwoFactor()->create();

    // First use should succeed
    $this->service->verifyRecoveryCode($user, 'recovery-code-1');

    // Second use should fail
    $user->refresh();
    $this->service->verifyRecoveryCode($user, 'recovery-code-1');
})->throws(ValidationException::class);

// --- verify (dispatch method) ---

test('verify dispatches to verifyCode when code is provided', function () {
    $user = User::factory()->withTwoFactor()->create();

    $mock = Mockery::mock(Google2FA::class);
    $mock->shouldReceive('verifyKey')->once()->andReturn(true);
    $this->app->instance(Google2FA::class, $mock);

    $this->service->verify($user, '123456', null);

    expect(true)->toBeTrue();
});

test('verify dispatches to verifyRecoveryCode when recovery code is provided', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->service->verify($user, null, 'recovery-code-1');

    $user->refresh();
    $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
    expect($codes)->not->toContain('recovery-code-1');
});

test('verify throws when neither code nor recovery code is provided', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->service->verify($user, null, null);
})->throws(ValidationException::class);

test('verify throws when both code and recovery code are empty strings', function () {
    $user = User::factory()->withTwoFactor()->create();

    // Empty strings are falsy in PHP, so both branches are skipped
    $this->service->verify($user, '', '');
})->throws(ValidationException::class);

test('verify prefers code over recovery code when both provided', function () {
    $user = User::factory()->withTwoFactor()->create();

    $mock = Mockery::mock(Google2FA::class);
    $mock->shouldReceive('verifyKey')->once()->andReturn(true);
    $this->app->instance(Google2FA::class, $mock);

    // When both are provided, code path is taken
    $this->service->verify($user, '123456', 'recovery-code-1');

    // Recovery code should NOT be consumed since code path was taken
    $user->refresh();
    $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
    expect($codes)->toContain('recovery-code-1');
});
