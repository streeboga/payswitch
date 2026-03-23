<?php

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

test('login with valid credentials returns user json', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['id', 'name', 'email'])
        ->assertJsonPath('id', $user->id);
});

test('login with invalid password returns 422', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('login with 2fa user returns two_factor flag', function () {
    $user = User::factory()->withTwoFactor()->create();

    $response = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertExactJson(['two_factor' => true]);
});

test('two-factor challenge with valid otp returns user json', function () {
    $user = User::factory()->withTwoFactor()->create();

    // Login first to store 2FA session
    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    // Mock Google2FA to return true
    $mock = Mockery::mock(Google2FA::class);
    $mock->shouldReceive('verifyKey')->andReturn(true);
    $this->app->instance(Google2FA::class, $mock);

    $response = $this->postJson('/two-factor-challenge', [
        'code' => '123456',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['id', 'name', 'email'])
        ->assertJsonPath('id', $user->id);
});

test('two-factor challenge with recovery code returns user json', function () {
    $user = User::factory()->withTwoFactor()->create();

    // Login first
    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response = $this->postJson('/two-factor-challenge', [
        'recovery_code' => 'recovery-code-1',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['id', 'name', 'email'])
        ->assertJsonPath('id', $user->id);
});

test('two-factor challenge with invalid code returns 422', function () {
    $user = User::factory()->withTwoFactor()->create();

    // Login first
    $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $mock = Mockery::mock(Google2FA::class);
    $mock->shouldReceive('verifyKey')->andReturn(false);
    $this->app->instance(Google2FA::class, $mock);

    $response = $this->postJson('/two-factor-challenge', [
        'code' => '000000',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('authenticated user can get own data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/user');

    $response->assertOk()
        ->assertJsonStructure(['id', 'name', 'email'])
        ->assertJsonPath('id', $user->id);
});

test('unauthenticated user gets 401', function () {
    $response = $this->getJson('/api/v1/user');

    $response->assertUnauthorized();
});

test('logout clears session', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/logout');

    $response->assertNoContent();

    $this->getJson('/api/v1/user')->assertUnauthorized();
});

test('login rate limiting after multiple failures', function () {
    // Override login rate limiter with strict production-like limit for this test
    RateLimiter::for('login', function ($request) {
        $email = strtolower((string) $request->string('email'));

        return Limit::perMinute(5)->by($email.'|'.$request->ip());
    });

    $user = User::factory()->create();

    // Exhaust the 5 attempts allowed per minute
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    // 6th attempt should be rate-limited (429)
    $response = $this->postJson('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429);
});

test('throttle key includes email and ip', function () {
    $request = Request::create('/login', 'POST', [
        'email' => 'Test@Example.COM',
    ]);

    $loginRequest = LoginRequest::createFrom($request);
    $loginRequest->setContainer(app());

    $throttleKey = $loginRequest->throttleKey();

    expect($throttleKey)->toContain('test@example.com');
    expect($throttleKey)->toContain('|');
});
