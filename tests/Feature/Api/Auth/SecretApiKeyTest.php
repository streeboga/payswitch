<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

function createMerchantWithKeys(): array
{
    $org = Organization::create(['name' => 'Secret Key Test Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Secret Key Merchant']);

    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Test Secret Key',
    ]);

    return [$merchant, $rawKey];
}

// --- No API key ---

test('request without api key to secret endpoint returns 401', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'api_key_missing');
});

// --- Invalid API key ---

test('request with invalid api key to secret endpoint returns 401', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
    ], ['api-key' => 'snd_invalid_key_that_does_not_exist']);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'invalid_api_key');
});

// --- Publishable key on secret endpoint ---

test('publishable key on secret endpoint returns 403', function () {
    [$merchant] = createMerchantWithKeys();

    $response = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
    ], ['api-key' => $merchant->publishable_key]);

    $response->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'secret_key_required');
});

// --- Valid secret key passes through ---

test('valid secret key passes through to the endpoint', function () {
    [$merchant, $rawKey] = createMerchantWithKeys();

    $response = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
    ], ['api-key' => $rawKey]);

    // Should not be 401/403 — may be 422 validation error but not an auth error
    expect($response->status())->not->toBe(401);
    expect($response->status())->not->toBe(403);
});
