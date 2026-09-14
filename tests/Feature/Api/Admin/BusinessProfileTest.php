<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Models\BusinessProfile;
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

test('профиль мерчанта читается по ключу мерчанта', function () {
    $this->postJson('/api/v1/profiles', [
        'merchant_id' => $this->merchant->key,
        'webhook_url' => 'https://invoice.gnzs.pro/api/v1/webhooks/payswitch',
    ], ['api-key' => 'admin_test_key'])->assertStatus(201);

    $this->getJson("/api/v1/merchants/{$this->merchant->key}/profile", ['api-key' => 'admin_test_key'])
        ->assertOk()
        ->assertJsonPath('data.attributes.webhook_url', 'https://invoice.gnzs.pro/api/v1/webhooks/payswitch');
});

test('ключ подписи лежит в базе зашифрованным, а API отдаёт его открытым', function () {
    $create = $this->postJson('/api/v1/profiles', [
        'merchant_id' => $this->merchant->key,
    ], ['api-key' => 'admin_test_key'])->assertStatus(201);

    $plain = $create->json('data.attributes.payment_response_hash_key');
    $raw = DB::table('business_profiles')->where('key', $create->json('data.id'))->value('payment_response_hash_key');

    expect($raw)->not->toBe($plain)
        ->and(Crypt::decryptString($raw))->toBe($plain);

    $this->patchJson("/api/v1/merchants/{$this->merchant->key}/profile", [
        'webhook_url' => 'https://example.com/hook',
    ], ['api-key' => 'admin_test_key'])->assertJsonPath('data.attributes.payment_response_hash_key', $plain);
});

test('миграция шифрует существующие ключи подписи и обратима', function () {
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $migration = require base_path('packages/streeboga/payment-data/database/migrations/2026_09_15_000100_encrypt_business_profiles_payment_response_hash_key.php');

    $migration->down();
    $plain = str_repeat('k', 64);
    DB::table('business_profiles')->where('id', $profile->id)->update(['payment_response_hash_key' => $plain]);
    DB::table('business_profiles')->insert([
        'key' => 'pro_nokey', 'merchant_account_id' => $this->merchant->id, 'name' => '', 'payment_response_hash_key' => null,
    ]);

    $migration->up();

    $raw = DB::table('business_profiles')->where('id', $profile->id)->value('payment_response_hash_key');
    expect($raw)->not->toBe($plain)
        ->and($profile->fresh()->payment_response_hash_key)->toBe($plain)
        ->and(DB::table('business_profiles')->where('key', 'pro_nokey')->value('payment_response_hash_key'))->toBeNull();

    $migration->down();
    expect(DB::table('business_profiles')->where('id', $profile->id)->value('payment_response_hash_key'))->toBe($plain);
    $migration->up();
});
