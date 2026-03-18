<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\ApiKey;

uses(RefreshDatabase::class);

// --- Key auto-generation ---

test('organization auto-generates key with org_ prefix on creation', function () {
    $org = Organization::create(['name' => 'Test Org']);

    expect($org->key)->toStartWith('org_')->not->toBeEmpty();
});

test('merchant account auto-generates key with merchant_ prefix', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create([
        'org_id' => $org->id,
        'name' => 'Test Merchant',
    ]);

    expect($merchant->key)->toStartWith('merchant_');
    expect($merchant->publishable_key)->toStartWith('pk_');
});

test('business profile auto-generates key with pro_ prefix', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create([
        'merchant_account_id' => $merchant->id,
        'webhook_url' => 'https://example.com/webhook',
    ]);

    expect($profile->key)->toStartWith('pro_');
    expect($profile->payment_response_hash_key)->toHaveLength(64);
});

// --- Relationships ---

test('organization has many merchant accounts', function () {
    $org = Organization::create(['name' => 'Org']);
    MerchantAccount::create(['org_id' => $org->id, 'name' => 'M1']);
    MerchantAccount::create(['org_id' => $org->id, 'name' => 'M2']);

    expect($org->merchantAccounts)->toHaveCount(2);
});

test('merchant account belongs to organization', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);

    expect($merchant->organization->id)->toBe($org->id);
});

test('merchant account has many business profiles', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    BusinessProfile::create(['merchant_account_id' => $merchant->id]);
    BusinessProfile::create(['merchant_account_id' => $merchant->id]);

    expect($merchant->businessProfiles)->toHaveCount(2);
});

test('business profile belongs to merchant account', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $merchant->id]);

    expect($profile->merchantAccount->id)->toBe($merchant->id);
});

test('merchant account has many api keys', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => bcrypt('test_key'),
        'key_prefix' => 'snd_testke',
        'name' => 'Key 1',
    ]);

    expect($merchant->apiKeys)->toHaveCount(1);
});

// --- API Key behavior ---

test('api key can be revoked', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $key = ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => bcrypt('test'),
        'key_prefix' => 'snd_test',
        'name' => 'Key',
    ]);

    expect($key->isRevoked())->toBeFalse();

    $key->revoke();

    expect($key->fresh()->isRevoked())->toBeTrue();
    expect($key->fresh()->revoked_at)->not->toBeNull();
});

test('api key expiration check works', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);

    $active = ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => bcrypt('test'),
        'key_prefix' => 'snd_test',
        'name' => 'Active',
        'expires_at' => now()->addYear(),
    ]);

    $expired = ApiKey::create([
        'merchant_account_id' => $merchant->id,
        'key_hash' => bcrypt('test2'),
        'key_prefix' => 'snd_test2',
        'name' => 'Expired',
        'expires_at' => now()->subDay(),
    ]);

    expect($active->isExpired())->toBeFalse();
    expect($expired->isExpired())->toBeTrue();
});

// --- Route key name ---

test('organization resolves by key column', function () {
    expect((new Organization)->getRouteKeyName())->toBe('key');
});

test('merchant account resolves by key column', function () {
    expect((new MerchantAccount)->getRouteKeyName())->toBe('key');
});

test('business profile resolves by key column', function () {
    expect((new BusinessProfile)->getRouteKeyName())->toBe('key');
});
