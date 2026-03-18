<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('profiles list returns json:api response', function () {
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/profiles', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'profiles');
});

test('profile create returns 201', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/profiles', [
            'data' => ['type' => 'profiles', 'attributes' => [
                'webhook_url' => 'https://example.com/webhook',
            ]],
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'profiles');
});

test('profile detail returns json:api resource', function () {
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id, 'webhook_url' => 'https://example.com']);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/profiles/{$profile->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'profiles')
        ->assertJsonPath('data.id', $profile->key);
});

test('profile update works', function () {
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/profiles/{$profile->key}", [
            'data' => ['type' => 'profiles', 'id' => $profile->key, 'attributes' => [
                'webhook_url' => 'https://new.example.com/hook',
            ]],
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.webhook_url', 'https://new.example.com/hook');
});

test('profiles require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/profiles', $this->headers);
    $response->assertUnauthorized();
});
