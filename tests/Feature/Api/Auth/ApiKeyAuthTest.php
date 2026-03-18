<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
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
