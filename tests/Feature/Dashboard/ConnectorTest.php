<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserRole;
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
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('connectors list returns json:api response', function () {
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'cloudpayments',
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
            'connector_name' => 'cloudpayments',
            'connector_type' => 'fiz_operations',
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
            'profile_id' => $profile->key,
            'test_mode' => true,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'connectors')
        ->assertJsonPath('data.attributes.connector_name', 'cloudpayments');
});

test('connector delete returns 204', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'cloudpayments',
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

test('connector show returns concrete attributes', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'test_sbp',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'key_adyen'],
        'payment_methods_enabled' => [['payment_method' => 'card'], ['payment_method' => 'wallet']],
        'test_mode' => false,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$mca->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'connectors')
        ->assertJsonPath('data.attributes.connector_name', 'test_sbp')
        ->assertJsonPath('data.attributes.connector_type', 'fiz_operations')
        ->assertJsonPath('data.attributes.test_mode', false)
        ->assertJsonPath('data.attributes.disabled', false)
        ->assertJsonPath('data.attributes.payment_methods_enabled.0.payment_method', 'card')
        ->assertJsonPath('data.attributes.payment_methods_enabled.1.payment_method', 'wallet')
        ->assertJsonPath('data.links.self', "/api/v1/dashboard/connectors/{$mca->key}");
});

test('connector update credentials via PATCH', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_old'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/connectors/{$mca->key}", [
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_new'],
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'connectors')
        ->assertJsonPath('data.attributes.connector_name', 'cloudpayments');

    $mca->refresh();
    expect($mca->connector_account_details)->toMatchArray(['api_key' => 'sk_new']);
});

test('connector update payment methods via PATCH', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/connectors/{$mca->key}", [
            'payment_methods_enabled' => [['payment_method' => 'card'], ['payment_method' => 'bank_transfer']],
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.payment_methods_enabled', [
            ['payment_method' => 'card'],
            ['payment_method' => 'bank_transfer'],
        ]);
});

test('connector enable/disable toggle via PATCH', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
        'disabled' => false,
    ]);

    // Disable
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/connectors/{$mca->key}", [
            'disabled' => true,
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.disabled', true);

    $mca->refresh();
    expect($mca->disabled)->toBeTrue();

    // Re-enable
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/connectors/{$mca->key}", [
            'disabled' => false,
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.disabled', false);

    $mca->refresh();
    expect($mca->disabled)->toBeFalse();
});

test('connector delete removes record from database', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/connectors/{$mca->key}", [], $this->headers)
        ->assertNoContent();

    $this->assertDatabaseMissing('merchant_connector_accounts', ['id' => $mca->id]);
});

test('connector create validation fails without required fields', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/connectors', [], $this->headers);

    $response->assertStatus(422)
        ->assertJsonFragment(['pointer' => '/connector_name'])
        ->assertJsonFragment(['pointer' => '/connector_type'])
        ->assertJsonFragment(['pointer' => '/connector_account_details']);
});

test('connector create validation fails with invalid types', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/connectors', [
            'connector_name' => 123,
            'connector_type' => 456,
            'connector_account_details' => 'not_an_array',
            'test_mode' => 'not_boolean',
        ], $this->headers);

    $response->assertStatus(422);
});

test('connector update validation fails with invalid types', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/connectors/{$mca->key}", [
            'disabled' => 'not_boolean',
            'connector_account_details' => 'not_an_array',
        ], $this->headers);

    $response->assertStatus(422)
        ->assertJsonFragment(['pointer' => '/disabled'])
        ->assertJsonFragment(['pointer' => '/connector_account_details']);
});

test('connector create with payment_methods_enabled asserts values', function () {
    $profile = $this->merchant->businessProfiles->first();

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/connectors', [
            'connector_name' => 'test_sbp',
            'connector_type' => 'fiz_operations',
            'connector_account_details' => ['auth_type' => 'BodyKey', 'merchant_id' => 'adyen_mid'],
            'profile_id' => $profile->key,
            'payment_methods_enabled' => [['payment_method' => 'card'], ['payment_method' => 'bank_transfer']],
            'test_mode' => false,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.connector_name', 'test_sbp')
        ->assertJsonPath('data.attributes.test_mode', false)
        ->assertJsonPath('data.attributes.payment_methods_enabled', [
            ['payment_method' => 'card'],
            ['payment_method' => 'bank_transfer'],
        ]);
});

test('connector create without profile_id uses default profile', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/connectors', [
            'connector_name' => 'test',
            'connector_type' => 'fiz_operations',
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'pk_test'],
            'test_mode' => true,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.connector_name', 'test');

    $connector = MerchantConnectorAccount::latest('id')->first();
    expect($connector->business_profile_id)->toBe($this->merchant->businessProfiles->first()->id);
});

test('connectors list returns correct connector_name values for multiple connectors', function () {
    $profileId = $this->merchant->businessProfiles->first()->id;

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profileId,
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_1'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profileId,
        'connector_name' => 'test_sbp',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'BodyKey', 'merchant_id' => 'mid'],
        'payment_methods_enabled' => [['payment_method' => 'bank_transfer']],
        'test_mode' => false,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/connectors', $this->headers);

    $response->assertOk()
        ->assertJsonCount(2, 'data');

    $names = collect($response->json('data'))->pluck('attributes.connector_name')->sort()->values()->all();
    expect($names)->toBe(['cloudpayments', 'test_sbp']);
});

test('dashboard offers only connectable connectors', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/connectors/connectable', $this->headers)
        ->assertOk()
        ->assertExactJson(['data' => ['cloudpayments', 'test', 'test_sbp']]);

    $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/connectors', [
            'connector_name' => 'tbank',
            'connector_type' => 'fiz_operations',
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'x'],
        ], $this->headers)
        ->assertStatus(422);
});
