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

function authorizePayment(int $amount = 10000): string
{
    $create = test()->postJson('/api/v1/payments', [
        'amount' => $amount,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], ['api-key' => test()->rawKey]);

    $create->assertStatus(201);
    $paymentId = $create->json('data.id');

    $confirm = test()->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], ['api-key' => test()->rawKey]);

    $confirm->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_capture');

    return $paymentId;
}

// --- Partial capture ---

test('partial capture captures less than authorized amount', function () {
    $paymentId = authorizePayment(10000);

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 5000,
    ], ['api-key' => $this->rawKey]);

    $response->assertOk()
        ->assertJsonPath('data.attributes.amount_received', 5000)
        ->assertJsonPath('data.attributes.amount_capturable', 5000)
        ->assertJsonPath('data.attributes.status', 'partially_captured_and_capturable');
});

test('capture with zero amount is rejected', function () {
    $paymentId = authorizePayment(10000);

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 0,
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'validation_error')
        ->assertJsonPath('errors.0.source.pointer', '/amount_to_capture');
});

test('capture with negative amount is rejected', function () {
    $paymentId = authorizePayment(10000);

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => -100,
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'validation_error')
        ->assertJsonPath('errors.0.source.pointer', '/amount_to_capture');
});

test('full capture of authorized amount succeeds', function () {
    $paymentId = authorizePayment(10000);

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 10000,
    ], ['api-key' => $this->rawKey]);

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount_received', 10000)
        ->assertJsonPath('data.attributes.amount_capturable', 0);
});
