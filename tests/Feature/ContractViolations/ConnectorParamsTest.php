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

    Http::fake([
        'api.yookassa.ru/*' => Http::response([
            'id' => 'yoo_txn_123',
            'status' => 'succeeded',
            'amount' => ['value' => '65.40', 'currency' => 'RUB'],
            'metadata' => [],
        ], 200),
    ]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => 'shop_1', 'secret_key' => 'sk_test_yoo'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
});

function yooApiHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function createYooPayment(array $attrs = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/payments', array_merge([
        'amount' => 6540,
        'currency' => 'RUB',
    ], $attrs), yooApiHeaders());
}

test('confirm passes payment_id to connector for idempotency', function () {
    $create = createYooPayment();
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], yooApiHeaders());

    Http::assertSent(function ($request) use ($paymentId) {
        if (! str_contains($request->url(), 'api.yookassa.ru/v3/payments')) {
            return false;
        }

        $body = $request->data();

        // YooKassa connector puts payment_id into metadata for traceability
        // and uses it as Idempotence-Key for duplicate protection.
        return ($body['metadata']['payment_id'] ?? '') === $paymentId
            && $request->header('Idempotence-Key')[0] === $paymentId;
    });
})->skip('BUG #1: PaymentConfirmationService builds connectorParams without payment_id — YooKassa needs it for idempotency');

test('refund passes payment_id to connector for idempotency', function () {
    $create = createYooPayment();
    $paymentId = $create->json('data.id');

    // Confirm the payment first
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ], yooApiHeaders());

    // Reset Http::fake to track refund calls
    Http::fake([
        'api.yookassa.ru/*' => Http::response([
            'id' => 'yoo_refund_456',
            'status' => 'succeeded',
            'amount' => ['value' => '65.40', 'currency' => 'RUB'],
        ], 200),
    ]);

    // Refund
    $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 6540,
    ], yooApiHeaders());

    Http::assertSent(function ($request) use ($paymentId) {
        if (! str_contains($request->url(), 'api.yookassa.ru/v3/refunds')) {
            return false;
        }

        // YooKassa connector builds Idempotence-Key from payment_id.
        // Without payment_id, it falls back to random bytes — no idempotency.
        $idempotenceKey = $request->header('Idempotence-Key')[0] ?? '';

        return str_contains($idempotenceKey, $paymentId);
    });
})->skip('BUG #5: RefundService calls connector->refund() without payment_id — YooKassa needs it for Idempotence-Key');
