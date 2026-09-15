<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['payswitch.admin_api_key' => 'admin_test_key']);
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
});

test('can add stripe connector to merchant', function () {
    $response = $this->postJson("/api/v1/merchants/{$this->merchant->key}/connectors", [
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'profile_id' => $this->profile->key,
        'connector_account_details' => [
            'auth_type' => 'HeaderKey',
            'api_key' => 'sk_test_xxx',
        ],
        'payment_methods_enabled' => [
            [
                'payment_method' => 'card',
                'payment_method_types' => [
                    ['payment_method_type' => 'credit', 'card_networks' => ['Visa', 'Mastercard']],
                ],
            ],
        ],
        'test_mode' => true,
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'connectors')
        ->assertJsonPath('data.attributes.connector_name', 'cloudpayments');

    expect($response->json('data.id'))->toStartWith('mca_');
});

test('connector credentials are stored encrypted', function () {
    $this->postJson("/api/v1/merchants/{$this->merchant->key}/connectors", [
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'profile_id' => $this->profile->key,
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_secret'],
        'test_mode' => true,
    ], ['api-key' => 'admin_test_key']);

    // Raw DB value should NOT contain the plaintext secret
    $raw = DB::table('merchant_connector_accounts')->first();
    expect($raw->connector_account_details)->not->toContain('sk_test_secret');
});

test('can list connectors for merchant', function () {
    // Create 2 connectors
    foreach (['cloudpayments', 'test'] as $name) {
        $this->postJson("/api/v1/merchants/{$this->merchant->key}/connectors", [
            'connector_name' => $name,
            'connector_type' => 'fiz_operations',
            'profile_id' => $this->profile->key,
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'test'],
            'test_mode' => true,
        ], ['api-key' => 'admin_test_key']);
    }

    $response = $this->getJson(
        "/api/v1/merchants/{$this->merchant->key}/connectors",
        ['api-key' => 'admin_test_key']
    );

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can delete connector', function () {
    $create = $this->postJson("/api/v1/merchants/{$this->merchant->key}/connectors", [
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'profile_id' => $this->profile->key,
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'test'],
        'test_mode' => true,
    ], ['api-key' => 'admin_test_key']);

    $connectorKey = $create->json('data.id');

    $this->deleteJson(
        "/api/v1/merchants/{$this->merchant->key}/connectors/{$connectorKey}",
        [],
        ['api-key' => 'admin_test_key']
    )->assertStatus(204);
});

test('can update connector via PATCH', function () {
    $create = $this->postJson("/api/v1/merchants/{$this->merchant->key}/connectors", [
        'connector_name' => 'cloudpayments',
        'connector_type' => 'fiz_operations',
        'profile_id' => $this->profile->key,
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'old'],
        'test_mode' => true,
    ], ['api-key' => 'admin_test_key']);

    $connectorKey = $create->json('data.id');

    $response = $this->patchJson(
        "/api/v1/merchants/{$this->merchant->key}/connectors/{$connectorKey}",
        [
            'disabled' => true,
        ],
        ['api-key' => 'admin_test_key']
    );

    $response->assertOk()
        ->assertJsonPath('data.attributes.disabled', true);
});

test('unverified connector cannot be added', function () {
    $this->postJson("/api/v1/merchants/{$this->merchant->key}/connectors", [
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'profile_id' => $this->profile->key,
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'test'],
    ], ['api-key' => 'admin_test_key'])->assertStatus(422);
});
