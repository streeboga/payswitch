<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('api keys list returns json:api response', function () {
    $rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Test Key',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/api-keys', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'api-keys');
});

test('api key create returns 201', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/api-keys', [
            'name' => 'New Key',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'api-keys');
});

test('api key of type admin cannot be created from dashboard', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/api-keys', ['name' => 'Admin?', 'type' => 'admin'], $this->headers)
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/api-keys', ['name' => 'Pub', 'type' => 'publishable'], $this->headers)
        ->assertCreated();
});

test('api key revoke returns 204', function () {
    $rawKey = IdGenerator::apiKey('sandbox');
    $apiKey = ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $rawKey),
        'key_prefix' => substr($rawKey, 0, 20),
        'name' => 'Test Key',
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/api-keys/{$apiKey->id}", [], $this->headers);

    $response->assertNoContent();
});

test('api keys require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/api-keys', $this->headers);

    $response->assertUnauthorized();
});
