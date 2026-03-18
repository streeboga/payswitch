<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('connectors list returns json:api response', function () {
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/connectors', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'connectors');
});

test('connector create returns 201', function () {
    $profile = $this->merchant->businessProfiles->first();

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/connectors', [
            'data' => ['type' => 'connectors', 'attributes' => [
                'connector_name' => 'stripe',
                'connector_type' => 'fiz_operations',
                'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
                'profile_id' => $profile->key,
                'test_mode' => true,
            ]],
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'connectors')
        ->assertJsonPath('data.attributes.connector_name', 'stripe');
});

test('connector delete returns 204', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/connectors/{$mca->key}", [], $this->headers);

    $response->assertNoContent();
});

test('connectors require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/connectors', $this->headers);

    $response->assertUnauthorized();
});
