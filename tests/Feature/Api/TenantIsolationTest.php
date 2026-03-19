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
    // --- Merchant A ---
    $orgA = Organization::create(['name' => 'Org A']);
    $this->merchantA = MerchantAccount::create(['org_id' => $orgA->id, 'name' => 'Merchant A']);
    $profileA = BusinessProfile::create(['merchant_account_id' => $this->merchantA->id]);

    $this->rawKeyA = IdGenerator::apiKey('sandbox');
    $this->apiKeyA = ApiKey::create([
        'merchant_account_id' => $this->merchantA->id,
        'key_hash' => hash('sha256', $this->rawKeyA),
        'key_prefix' => substr($this->rawKeyA, 0, 20),
        'name' => 'Key A',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchantA->id,
        'business_profile_id' => $profileA->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_a'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    // --- Merchant B ---
    $orgB = Organization::create(['name' => 'Org B']);
    $this->merchantB = MerchantAccount::create(['org_id' => $orgB->id, 'name' => 'Merchant B']);
    $profileB = BusinessProfile::create(['merchant_account_id' => $this->merchantB->id]);

    $this->rawKeyB = IdGenerator::apiKey('sandbox');
    $this->apiKeyB = ApiKey::create([
        'merchant_account_id' => $this->merchantB->id,
        'key_hash' => hash('sha256', $this->rawKeyB),
        'key_prefix' => substr($this->rawKeyB, 0, 20),
        'name' => 'Key B',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchantB->id,
        'business_profile_id' => $profileB->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_b'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function headersA(): array
{
    return ['api-key' => test()->rawKeyA];
}

function headersB(): array
{
    return ['api-key' => test()->rawKeyB];
}

function createPaymentFor(string $which, array $attrs = []): string
{
    $headers = $which === 'A' ? headersA() : headersB();

    $response = test()->postJson('/api/v1/payments', array_merge([
        'amount' => 6540,
        'currency' => 'USD',
    ], $attrs), $headers);

    return $response->json('data.id');
}

function createConfirmedPaymentFor(string $which): string
{
    $headers = $which === 'A' ? headersA() : headersB();

    $response = test()->postJson('/api/v1/payments', [
        'amount' => 6540,
        'currency' => 'USD',
        'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], $headers);

    return $response->json('data.id');
}

// --- Cross-merchant read ---

test('merchant B cannot read merchant A payment', function () {
    $paymentId = createPaymentFor('A');

    $this->getJson("/api/v1/payments/{$paymentId}", headersB())
        ->assertStatus(404);
});

// --- Cross-merchant confirm ---

test('merchant B cannot confirm merchant A payment', function () {
    $paymentId = createPaymentFor('A');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], headersB())->assertStatus(404);
});

// --- Cross-merchant capture ---

test('merchant B cannot capture merchant A payment', function () {
    $paymentId = createPaymentFor('A', ['capture_method' => 'manual']);

    // Confirm as merchant A first
    test()->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], headersA())->assertOk();

    // Attempt capture as merchant B
    $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 6540,
    ], headersB())->assertStatus(404);
});

// --- Cross-merchant cancel ---

test('merchant B cannot cancel merchant A payment', function () {
    $paymentId = createPaymentFor('A');

    $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], headersB())
        ->assertStatus(404);
});

// --- Cross-merchant refund creation ---

test('merchant B cannot refund merchant A payment', function () {
    $paymentId = createConfirmedPaymentFor('A');

    $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 1000,
    ], headersB())->assertStatus(404);
});

// --- Cross-merchant refund read ---

test('merchant B cannot read merchant A refund', function () {
    $paymentId = createConfirmedPaymentFor('A');

    $refund = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 1000,
    ], headersA());

    $refundId = $refund->json('data.id');

    $this->getJson("/api/v1/refunds/{$refundId}", headersB())
        ->assertStatus(404);
});

// --- Scoped list ---

test('payment creation is scoped per merchant', function () {
    // Merchant A: 2 payments
    createPaymentFor('A');
    createPaymentFor('A');

    // Merchant B: 1 payment
    createPaymentFor('B');

    // Verify via GET that each merchant only sees their own
    $responseA1 = $this->getJson('/api/v1/payments/' . createPaymentFor('A'), headersA());
    $responseA1->assertOk();

    // Merchant B cannot see merchant A's payments
    $paymentA = createPaymentFor('A');
    $this->getJson("/api/v1/payments/{$paymentA}", headersB())
        ->assertStatus(404);

    // But merchant B can see their own
    $paymentB = createPaymentFor('B');
    $this->getJson("/api/v1/payments/{$paymentB}", headersB())
        ->assertOk();
});

// --- Revoked API key ---

test('revoked API key returns 401', function () {
    $this->apiKeyA->revoke();

    $this->getJson('/api/v1/payments/pay_test', headersA())
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'api_key_revoked');
});

// --- Invalid API key ---

test('invalid API key returns 401', function () {
    $this->getJson('/api/v1/payments/pay_test', [
        'api-key' => 'snd_completely_invalid_key_value_here',
    ])->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'invalid_api_key');
});

// --- Missing API key ---

test('missing API key returns 401', function () {
    $this->getJson('/api/v1/payments/pay_test')
        ->assertStatus(401);
});
