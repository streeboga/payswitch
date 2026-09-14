<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

function createMerchantWithApiKey(): array
{
    $org = Organization::create(['name' => 'Auth Test Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Auth Merchant']);

    $rawKey = IdGenerator::apiKey('sandbox');
    $apiKey = ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Test Key',
    ]);

    return [$merchant, $rawKey, $apiKey];
}

// --- Missing API key ---

test('request without api-key header returns 401 JSON:API error', function () {
    $response = $this->getJson('/api/v1/payments/pay_nonexistent');

    $response->assertStatus(401)
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonStructure(['errors' => [['status', 'code', 'title', 'detail']]]);
});

// --- Invalid API key ---

test('request with invalid api key returns 401', function () {
    $response = $this->getJson('/api/v1/payments/pay_nonexistent', [
        'api-key' => 'snd_this_key_does_not_exist_at_all',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'invalid_api_key');
});

// --- Valid secret key ---

test('request with valid secret key sets merchant context', function () {
    [$merchant, $rawKey] = createMerchantWithApiKey();

    $response = $this->getJson('/api/v1/payments/pay_nonexistent', [
        'api-key' => $rawKey,
    ]);

    // Should not be 401 — payment not found is 404, not auth error
    expect($response->status())->not->toBe(401);
});

// --- Admin key ---

test('valid admin key authenticates for admin endpoints', function () {
    config(['payswitch.admin_api_key' => 'admin_test_key_123']);

    $response = $this->postJson('/api/v1/organizations', [
        'name' => 'New Org',
    ], ['api-key' => 'admin_test_key_123']);

    expect($response->status())->not->toBe(401);
});

test('weak admin key is not accepted in production', function (string $weakKey) {
    config(['payswitch.admin_api_key' => $weakKey]);
    $this->app->detectEnvironment(fn () => 'production');

    $this->postJson('/api/v1/organizations', ['name' => 'New Org'], ['api-key' => $weakKey])
        ->assertStatus(401);
})->with([
    'known value from .env.example' => 'admin_test_key_for_development',
    'shorter than 32 characters' => 'short_but_unique_admin_key_1234',
]);

test('strong admin key is accepted in production', function () {
    $strongKey = bin2hex(random_bytes(32));
    config(['payswitch.admin_api_key' => $strongKey]);
    $this->app->detectEnvironment(fn () => 'production');

    $this->postJson('/api/v1/organizations', ['name' => 'New Org'], ['api-key' => $strongKey])
        ->assertCreated();
});

test('secret key on admin endpoint returns 403', function () {
    [$merchant, $rawKey] = createMerchantWithApiKey();

    $response = $this->postJson('/api/v1/organizations', [
        'name' => 'Org',
    ], ['api-key' => $rawKey]);

    $response->assertStatus(403);
});

// --- Revoked key ---

test('revoked key returns 401 with api_key_revoked code', function () {
    [$merchant, $rawKey, $apiKey] = createMerchantWithApiKey();
    $apiKey->revoke();

    $response = $this->getJson('/api/v1/payments/pay_nonexistent', [
        'api-key' => $rawKey,
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'api_key_revoked');
});

// --- Expired key ---

test('expired key returns 401 with api_key_expired code', function () {
    [$merchant, $rawKey, $apiKey] = createMerchantWithApiKey();
    $apiKey->update(['expires_at' => now()->subDay()]);

    $response = $this->getJson('/api/v1/payments/pay_nonexistent', [
        'api-key' => $rawKey,
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'api_key_expired');
});

// --- Key type from api_keys.type (С4) ---

function createApiKeyOfType(MerchantAccount $merchant, string $type, ?string $rawKey = null): array
{
    $rawKey ??= IdGenerator::apiKey('sandbox');
    $apiKey = ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => "{$type} key",
        'type' => $type,
    ]);

    return [$rawKey, $apiKey];
}

test('publishable key from api_keys is not accepted as secret', function () {
    [$merchant] = createMerchantWithApiKey();
    [$rawKey] = createApiKeyOfType($merchant, 'publishable');

    $this->getJson('/api/v1/payments', ['api-key' => $rawKey])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'secret_key_required');
});

test('admin-type key from api_keys never grants admin api and works as merchant secret', function () {
    [$merchant] = createMerchantWithApiKey();
    [$rawKey] = createApiKeyOfType($merchant, 'admin');

    $this->postJson('/api/v1/organizations', ['name' => 'Org'], ['api-key' => $rawKey])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'admin_key_required');

    $this->getJson('/api/v1/payments', ['api-key' => $rawKey])->assertOk();
});

test('keys sharing the same key_prefix both authenticate', function () {
    [$merchantA] = createMerchantWithApiKey();
    [$merchantB] = createMerchantWithApiKey();

    // ULID одной миллисекунды: первые 20 символов совпадают.
    $prefix = 'snd_01J0000000AAAAAA';
    [$rawA] = createApiKeyOfType($merchantA, 'secret', $prefix.'BBBBBBBBBB');
    [$rawB] = createApiKeyOfType($merchantB, 'secret', $prefix.'CCCCCCCCCC');

    expect(substr($rawA, 0, 20))->toBe(substr($rawB, 0, 20));

    $this->getJson('/api/v1/payments', ['api-key' => $rawA])->assertOk();
    $this->getJson('/api/v1/payments', ['api-key' => $rawB])->assertOk();
});

test('revoked status is not revealed by key prefix alone', function () {
    [$merchant, $rawKey, $apiKey] = createMerchantWithApiKey();
    $apiKey->revoke();

    $forged = substr($rawKey, 0, 20).str_repeat('Z', strlen($rawKey) - 20);

    $this->getJson('/api/v1/payments/pay_nonexistent', ['api-key' => $forged])
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'invalid_api_key');
});

// --- Rate limiting: publishable по мерчанту и IP, неудачные попытки по IP (С8) ---

test('publishable limit is counted per merchant and ip', function () {
    [$merchant] = createMerchantWithApiKey();
    $payment = PaymentIntent::create([
        'merchant_account_id' => $merchant->id,
        'amount' => 10000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'session_expiry' => 900,
        'expires_on' => now()->addMinutes(15),
    ]);
    $url = "/api/v1/payments/{$payment->key}";
    $headers = ['api-key' => $merchant->publishable_key, 'X-Client-Secret' => $payment->client_secret];
    $limit = config('payswitch.rate_limit.publishable');

    for ($i = 0; $i < $limit; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson($url, $headers)->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson($url, $headers)->assertStatus(429);
    // Другой плательщик того же мерчанта чекаут не теряет.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson($url, $headers)->assertOk();
});

test('failed api key attempts are limited per ip', function () {
    $max = config('payswitch.rate_limit.unauthenticated');

    for ($i = 0; $i < $max; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
            ->getJson('/api/v1/payments', ['api-key' => 'snd_wrong_key_'.$i])
            ->assertStatus(401);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
        ->getJson('/api/v1/payments', ['api-key' => 'snd_wrong_key_last'])
        ->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
        ->getJson('/api/v1/payments', ['api-key' => 'snd_wrong_key_other'])
        ->assertStatus(401);
});

// --- Rate limiting ---

test('api requests are rate limited', function () {
    [$merchant, $rawKey] = createMerchantWithApiKey();
    $limit = config('payswitch.rate_limit.secret', 120);

    // Send limit+1 requests
    for ($i = 0; $i <= $limit; $i++) {
        $response = $this->getJson('/api/v1/payments/pay_test', [
            'api-key' => $rawKey,
        ]);
    }

    $response->assertStatus(429);
});
