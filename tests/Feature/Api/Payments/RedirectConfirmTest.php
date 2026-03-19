<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Support\IdGenerator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $this->rawKey = IdGenerator::apiKey('sandbox');
    ApiKey::create([
        'merchant_account_id' => $this->merchant->id,
        'key_hash' => hash('sha256', $this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_xxx'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function redirectApiHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function redirectCreatePayment(array $attrs = []): TestResponse
{
    return test()->postJson('/api/v1/payments', array_merge([
        'amount' => 6540,
        'currency' => 'USD',
        'return_url' => 'https://merchant.com/return',
    ], $attrs), redirectApiHeaders());
}

// --- Redirect flow tests ---

test('confirm without card data returns redirect_url and requires_customer_action', function () {
    $create = redirectCreatePayment();
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
    ], redirectApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_customer_action');

    // Verify metadata contains redirect_url
    $metadata = $response->json('data.attributes.metadata');
    expect($metadata)->toHaveKey('redirect_url');
    expect($metadata['redirect_url'])->toContain('https://test-psp.example.com/pay/');
    expect($metadata)->toHaveKey('session_id');
    expect($metadata)->toHaveKey('redirect_method', 'GET');
});

test('confirm with card data still works (existing flow)', function () {
    $create = redirectCreatePayment();
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], redirectApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount_received', 6540);
});

test('redirect confirm creates payment attempt with pending status', function () {
    $create = redirectCreatePayment();
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
    ], redirectApiHeaders());

    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'test',
        'status' => 'requires_action',
    ]);
});

test('redirect confirm stores connector name on payment', function () {
    $create = redirectCreatePayment();
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
    ], redirectApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.connector', 'test');
});

test('redirect confirm with empty payment_method_data triggers redirect flow', function () {
    $create = redirectCreatePayment();
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [],
    ], redirectApiHeaders());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_customer_action');

    $metadata = $response->json('data.attributes.metadata');
    expect($metadata['redirect_url'])->toContain('https://test-psp.example.com/pay/');
});

test('redirect confirm on expired payment returns error', function () {
    $create = redirectCreatePayment(['session_expiry' => 60]);
    $paymentId = $create->json('data.id');

    // Manually expire the payment
    PaymentIntent::where('key', $paymentId)
        ->update(['expires_on' => now()->subMinutes(5)]);

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
    ], redirectApiHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'payment_expired');
});
