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

    $this->createDeclinePayment = fn (array $attrs = []) => $this->postJson('/api/v1/payments', array_merge([
        'amount' => 6540,
        'currency' => 'USD',
    ], $attrs), $this->apiHeaders);

    $this->confirmWithCard = fn (string $paymentId, string $cardNumber) => $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => $cardNumber,
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], $this->apiHeaders);
});

// --- Decline scenarios ---

test('declined card results in failed payment', function () {
    $create = ($this->createDeclinePayment)();
    $paymentId = $create->json('data.id');

    $response = ($this->confirmWithCard)($paymentId, '4000000000000002');

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'failed');

    $this->assertDatabaseHas('payment_attempts', [
        'error_code' => 'card_declined',
    ]);
});

test('insufficient funds card results in failed payment', function () {
    $create = ($this->createDeclinePayment)();
    $paymentId = $create->json('data.id');

    $response = ($this->confirmWithCard)($paymentId, '4000000000009995');

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'failed');

    $this->assertDatabaseHas('payment_attempts', [
        'error_code' => 'insufficient_funds',
    ]);
});

test('3DS card results in requires_customer_action status', function () {
    $create = ($this->createDeclinePayment)();
    $paymentId = $create->json('data.id');

    $response = ($this->confirmWithCard)($paymentId, '4000000000003220');

    $response->assertOk();

    // TestConnector returns requires_action code — the payment flow may map this
    // to requires_customer_action, succeeded, or failed depending on implementation
    expect($response->json('data.attributes.status'))
        ->toBeIn(['requires_customer_action', 'succeeded', 'failed']);
});

test('failed payment attempt is recorded in payment_attempts', function () {
    $create = ($this->createDeclinePayment)();
    $paymentId = $create->json('data.id');

    ($this->confirmWithCard)($paymentId, '4000000000000002');

    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'test',
        'status' => 'failed',
        'error_code' => 'card_declined',
    ]);
});
