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

test('capabilities endpoint returns data for stripe connector', function () {
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
        ->getJson("/api/v1/dashboard/connectors/{$mca->key}/capabilities", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'connector_capabilities')
        ->assertJsonPath('data.id', $mca->key)
        ->assertJsonStructure(['data' => [
            'type',
            'id',
            'attributes' => [
                'display_name',
                'logo_path',
                'direct_methods',
                'fallback_session_type',
                'amount_unit',
            ],
        ]]);
});

test('capabilities endpoint returns correct data for yookassa connector', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '123', 'secret_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$mca->key}/capabilities", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'connector_capabilities')
        ->assertJsonPath('data.attributes.display_name.ru', 'ЮKassa')
        ->assertJsonPath('data.attributes.display_name.en', 'YooKassa')
        ->assertJsonPath('data.attributes.fallback_session_type', 'redirect')
        ->assertJsonPath('data.attributes.amount_unit', 'rubles')
        ->assertJsonPath('data.attributes.direct_methods', ['card', 'sbp']);
});

test('capabilities endpoint requires authentication', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'stripe',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $response = $this->getJson("/api/v1/dashboard/connectors/{$mca->key}/capabilities", $this->headers);

    $response->assertUnauthorized();
});

test('capabilities endpoint returns empty for unknown connector driver', function () {
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->merchant->businessProfiles->first()->id,
        'connector_name' => 'unknown_psp',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['api_key' => 'test'],
        'payment_methods_enabled' => [],
        'test_mode' => true,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/connectors/{$mca->key}/capabilities", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'connector_capabilities')
        ->assertJsonPath('data.attributes', []);
});
