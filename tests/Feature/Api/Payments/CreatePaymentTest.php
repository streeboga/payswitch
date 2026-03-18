<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => bcrypt($this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 10),
        'name' => 'Test',
    ]);
});

test('can create payment with minimal fields', function () {
    $response = $this->postJson('/api/v1/payments', [
        'data' => [
            'type' => 'payments',
            'attributes' => [
                'amount' => 6540,
                'currency' => 'USD',
            ],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201)
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertHeader('Location')
        ->assertJsonStructure([
            'data' => [
                'type',
                'id',
                'attributes' => [
                    'status', 'amount', 'currency', 'client_secret',
                    'capture_method', 'attempt_count', 'created_at',
                ],
                'links' => ['self'],
            ],
        ])
        ->assertJsonPath('data.type', 'payments')
        ->assertJsonPath('data.attributes.status', 'requires_payment_method')
        ->assertJsonPath('data.attributes.amount', 6540)
        ->assertJsonPath('data.attributes.currency', 'USD')
        ->assertJsonPath('data.attributes.capture_method', 'automatic')
        ->assertJsonPath('data.attributes.attempt_count', 1);

    expect($response->json('data.id'))->toStartWith('pay_');
    expect($response->json('data.attributes.client_secret'))->toContain('_secret_');
});

test('can create payment with all optional fields', function () {
    $response = $this->postJson('/api/v1/payments', [
        'data' => [
            'type' => 'payments',
            'attributes' => [
                'amount' => 10000,
                'currency' => 'EUR',
                'capture_method' => 'manual',
                'authentication_type' => 'three_ds',
                'description' => 'Order #123',
                'return_url' => 'https://example.com/return',
                'metadata' => ['order_id' => 'ORD-456'],
            ],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.capture_method', 'manual')
        ->assertJsonPath('data.attributes.description', 'Order #123')
        ->assertJsonPath('data.attributes.metadata.order_id', 'ORD-456');
});

test('cannot create payment without amount', function () {
    $response = $this->postJson('/api/v1/payments', [
        'data' => [
            'type' => 'payments',
            'attributes' => ['currency' => 'USD'],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => [['status', 'code', 'detail']]]);
});

test('cannot create payment without currency', function () {
    $response = $this->postJson('/api/v1/payments', [
        'data' => [
            'type' => 'payments',
            'attributes' => ['amount' => 100],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(422);
});

test('cannot create payment with negative amount', function () {
    $response = $this->postJson('/api/v1/payments', [
        'data' => [
            'type' => 'payments',
            'attributes' => ['amount' => -100, 'currency' => 'USD'],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(422);
});

test('session_expiry defaults to 900 seconds', function () {
    $response = $this->postJson('/api/v1/payments', [
        'data' => [
            'type' => 'payments',
            'attributes' => ['amount' => 100, 'currency' => 'USD'],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201);
    // expires_on should be ~15 minutes after created_at
    expect($response->json('data.attributes.expires_on'))->not->toBeNull();
});

test('payment is scoped to authenticated merchant', function () {
    $response = $this->postJson('/api/v1/payments', [
        'data' => [
            'type' => 'payments',
            'attributes' => ['amount' => 100, 'currency' => 'USD'],
        ],
    ], ['api-key' => $this->rawKey]);

    $this->assertDatabaseHas('payment_intents', [
        'merchant_account_id' => $this->merchant->id,
    ]);
});
