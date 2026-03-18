<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['payswitch.admin_api_key' => 'admin_test_key']);
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
});

test('can create business profile with webhook url', function () {
    $response = $this->postJson('/api/v1/profiles', [
        'data' => [
            'type' => 'profiles',
            'attributes' => [
                'merchant_id' => $this->merchant->key,
                'webhook_url' => 'https://example.com/webhook',
            ],
        ],
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'profiles');

    expect($response->json('data.id'))->toStartWith('pro_');
    expect($response->json('data.attributes.payment_response_hash_key'))->toHaveLength(64);
});

test('can retrieve business profile', function () {
    $create = $this->postJson('/api/v1/profiles', [
        'data' => [
            'type' => 'profiles',
            'attributes' => [
                'merchant_id' => $this->merchant->key,
                'webhook_url' => 'https://example.com/hook',
            ],
        ],
    ], ['api-key' => 'admin_test_key']);

    $profileKey = $create->json('data.id');

    $this->getJson("/api/v1/profiles/{$profileKey}", ['api-key' => 'admin_test_key'])
        ->assertOk()
        ->assertJsonPath('data.id', $profileKey);
});

test('auto-generates payment_response_hash_key if not provided', function () {
    $response = $this->postJson('/api/v1/profiles', [
        'data' => [
            'type' => 'profiles',
            'attributes' => [
                'merchant_id' => $this->merchant->key,
            ],
        ],
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201);
    expect($response->json('data.attributes.payment_response_hash_key'))->not->toBeNull();
});
