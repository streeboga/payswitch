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
        'key_hash' => bcrypt($this->rawKey),
        'key_prefix' => substr($this->rawKey, 0, 20),
        'name' => 'Test',
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => encrypt(json_encode(['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'])),
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function createAndConfirmPayment(): string
{
    $create = test()->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => [
            'amount' => 6540, 'currency' => 'USD', 'confirm' => true,
            'payment_method' => 'card',
            'payment_method_data' => ['card' => ['card_number' => '4242424242424242', 'card_exp_month' => '12', 'card_exp_year' => '2030', 'card_cvc' => '123']],
        ]],
    ], ['api-key' => test()->rawKey]);

    return $create->json('data.id');
}

test('can create full refund for succeeded payment', function () {
    $paymentId = createAndConfirmPayment();

    $response = $this->postJson('/api/v1/refunds', [
        'data' => [
            'type' => 'refunds',
            'attributes' => [
                'payment_id' => $paymentId,
                'amount' => 6540,
                'reason' => 'Customer request',
            ],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'refunds')
        ->assertJsonPath('data.attributes.amount', 6540)
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.currency', 'USD');

    expect($response->json('data.id'))->toStartWith('ref_');
});

test('can create partial refund', function () {
    $paymentId = createAndConfirmPayment();

    $response = $this->postJson('/api/v1/refunds', [
        'data' => [
            'type' => 'refunds',
            'attributes' => [
                'payment_id' => $paymentId,
                'amount' => 3000,
            ],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.amount', 3000);
});

test('cannot refund more than payment amount', function () {
    $paymentId = createAndConfirmPayment();

    $response = $this->postJson('/api/v1/refunds', [
        'data' => [
            'type' => 'refunds',
            'attributes' => [
                'payment_id' => $paymentId,
                'amount' => 99999,
            ],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(400);
});

test('cannot refund non-succeeded payment', function () {
    // Create payment without confirming
    $create = $this->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => ['amount' => 100, 'currency' => 'USD']],
    ], ['api-key' => $this->rawKey]);

    $paymentId = $create->json('data.id');

    $response = $this->postJson('/api/v1/refunds', [
        'data' => [
            'type' => 'refunds',
            'attributes' => ['payment_id' => $paymentId, 'amount' => 100],
        ],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(400);
});

test('can retrieve refund', function () {
    $paymentId = createAndConfirmPayment();

    $create = $this->postJson('/api/v1/refunds', [
        'data' => ['type' => 'refunds', 'attributes' => ['payment_id' => $paymentId, 'amount' => 1000]],
    ], ['api-key' => $this->rawKey]);

    $refundId = $create->json('data.id');

    $response = $this->getJson("/api/v1/refunds/{$refundId}", ['api-key' => $this->rawKey]);

    $response->assertOk()
        ->assertJsonPath('data.id', $refundId)
        ->assertJsonPath('data.attributes.amount', 1000);
});

test('refund uses same connector as original payment', function () {
    $paymentId = createAndConfirmPayment();

    $response = $this->postJson('/api/v1/refunds', [
        'data' => ['type' => 'refunds', 'attributes' => ['payment_id' => $paymentId, 'amount' => 1000]],
    ], ['api-key' => $this->rawKey]);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.connector', 'test');
});
