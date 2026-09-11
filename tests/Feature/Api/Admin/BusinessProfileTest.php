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
        'merchant_id' => $this->merchant->key,
        'webhook_url' => 'https://example.com/webhook',
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'profiles');

    expect($response->json('data.id'))->toStartWith('pro_');
    expect($response->json('data.attributes.payment_response_hash_key'))->toHaveLength(64);
});

test('can retrieve business profile', function () {
    $create = $this->postJson('/api/v1/profiles', [
        'merchant_id' => $this->merchant->key,
        'webhook_url' => 'https://example.com/hook',
    ], ['api-key' => 'admin_test_key']);

    $profileKey = $create->json('data.id');

    $this->getJson("/api/v1/profiles/{$profileKey}", ['api-key' => 'admin_test_key'])
        ->assertOk()
        ->assertJsonPath('data.id', $profileKey);
});

test('auto-generates payment_response_hash_key if not provided', function () {
    $response = $this->postJson('/api/v1/profiles', [
        'merchant_id' => $this->merchant->key,
    ], ['api-key' => 'admin_test_key']);

    $response->assertStatus(201);
    expect($response->json('data.attributes.payment_response_hash_key'))->not->toBeNull();
});

test('адрес вебхука ставится по ключу мерчанта и отдаёт ключ подписи', function () {
    $this->postJson('/api/v1/profiles', [
        'merchant_id' => $this->merchant->key,
    ], ['api-key' => 'admin_test_key'])->assertStatus(201);

    $response = $this->patchJson("/api/v1/merchants/{$this->merchant->key}/profile", [
        'webhook_url' => 'https://api.gnzs.pro/internal/webhooks/payswitch/abc',
    ], ['api-key' => 'admin_test_key']);

    $response->assertOk()
        ->assertJsonPath('data.attributes.webhook_url', 'https://api.gnzs.pro/internal/webhooks/payswitch/abc');

    expect($response->json('data.attributes.payment_response_hash_key'))->toHaveLength(64);

    $this->assertDatabaseHas('business_profiles', [
        'merchant_account_id' => $this->merchant->id,
        'webhook_url' => 'https://api.gnzs.pro/internal/webhooks/payswitch/abc',
    ]);
});

test('мерчанту без профиля адрес вебхука не поставить', function () {
    $this->patchJson("/api/v1/merchants/{$this->merchant->key}/profile", [
        'webhook_url' => 'https://example.com/hook',
    ], ['api-key' => 'admin_test_key'])->assertNotFound();
});

test('чужой ключ подписи через API не переставить', function () {
    $create = $this->postJson('/api/v1/profiles', [
        'merchant_id' => $this->merchant->key,
    ], ['api-key' => 'admin_test_key']);

    $original = $create->json('data.attributes.payment_response_hash_key');

    $response = $this->patchJson("/api/v1/merchants/{$this->merchant->key}/profile", [
        'webhook_url' => 'https://example.com/hook',
        'payment_response_hash_key' => str_repeat('a', 64),
    ], ['api-key' => 'admin_test_key']);

    expect($response->json('data.attributes.payment_response_hash_key'))->toBe($original);
});
