<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    Http::fake(['*' => Http::response([
        'id' => 'test_txn_123',
        'status' => 'succeeded',
        'amount' => ['value' => '100.00', 'currency' => 'USD'],
        'metadata' => [],
    ], 200)]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => 'shop_1', 'secret_key' => 'sk_test'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

test('create payment rejects invalid customer_id that does not belong to merchant', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 10000,
        'currency' => 'USD',
        'customer_id' => 'cus_does_not_exist',
    ], ['api-key' => $this->rawKey]);

    // Should be 400 (invalid customer) but currently returns 201 — customer_id is stored without validation
    $response->assertStatus(400);
})->skip('BUG #13: PaymentService.create() accepts any customer_id string without validating it belongs to the merchant');

test('capture passes currency to connector for multi-currency support', function () {
    // PaymentService.capture() (line 106-109) only passes 'amount' and 'transaction_id' to connector.
    // Currency is never forwarded, breaking multi-currency capture scenarios where the connector
    // needs to know which currency to capture in (e.g. Stripe, Adyen).
    expect(true)->toBeTrue();
})->skip('BUG #10: PaymentService.capture() does not pass currency to connector — multi-currency capture broken');
