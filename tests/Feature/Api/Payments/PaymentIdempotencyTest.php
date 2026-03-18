<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->apiHeaders = ['api-key' => $this->rawKey];

    $this->createIdempotencyPayment = fn (array $attrs = []) => $this->postJson('/api/v1/payments', array_merge([
        'amount' => 6540,
        'currency' => 'USD',
    ], $attrs), $this->apiHeaders);
});

// --- Idempotency ---

test('duplicate payment_id returns existing payment instead of creating new', function () {
    // Create first payment to get the system-generated key
    $first = ($this->createIdempotencyPayment)();
    $first->assertStatus(201);
    $firstId = $first->json('data.id');

    // Use that key as payment_id — the service looks up by key
    $second = ($this->createIdempotencyPayment)(['payment_id' => $firstId]);
    $secondId = $second->json('data.id');

    expect($secondId)->toBe($firstId);
});

test('different payment_id creates different payments', function () {
    $first = ($this->createIdempotencyPayment)(['payment_id' => 'order_aaa']);
    $first->assertStatus(201);
    $firstId = $first->json('data.id');

    $second = ($this->createIdempotencyPayment)(['payment_id' => 'order_bbb']);
    $second->assertStatus(201);
    $secondId = $second->json('data.id');

    expect($secondId)->not->toBe($firstId);
});

test('payment without payment_id always creates new', function () {
    $first = ($this->createIdempotencyPayment)();
    $first->assertStatus(201);
    $firstId = $first->json('data.id');

    $second = ($this->createIdempotencyPayment)();
    $second->assertStatus(201);
    $secondId = $second->json('data.id');

    expect($secondId)->not->toBe($firstId);
});
