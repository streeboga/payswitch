<?php

declare(strict_types=1);

use App\Services\PaymentConfirmationService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Support\IdGenerator;

covers(PaymentService::class, PaymentConfirmationService::class);

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

function headers(): array
{
    return ['api-key' => test()->rawKey];
}

function cardData(): array
{
    return [
        'payment_method' => 'card',
        'payment_method_data' => [
            'card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
        ],
    ];
}

// ─── Create: exact DB assertions ───────────────────────────────────────────────

test('create stores exact amount, currency, status and capture_method in DB', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 7777,
        'currency' => 'eur',
        'capture_method' => 'manual',
    ], headers());

    $response->assertStatus(201);
    $paymentId = $response->json('data.id');

    $this->assertDatabaseHas('payment_intents', [
        'key' => $paymentId,
        'merchant_account_id' => $this->merchant->id,
        'amount' => 7777,
        'currency' => 'EUR',
        'status' => 'requires_payment_method',
        'capture_method' => 'manual',
    ]);
});

test('create sets amount_capturable equal to amount', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
    ], headers());

    $response->assertStatus(201);

    $payment = PaymentIntent::where('key', $response->json('data.id'))->first();
    expect($payment->amount_capturable)->toBe(5000);
    expect($payment->attempt_count)->toBe(1);
});

test('create uppercases currency', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 100,
        'currency' => 'gbp',
    ], headers());

    $response->assertStatus(201);

    $payment = PaymentIntent::where('key', $response->json('data.id'))->first();
    expect($payment->currency)->toBe('GBP');
});

test('create stores description and metadata', function () {
    $response = $this->postJson('/api/v1/payments', [
        'amount' => 100,
        'currency' => 'USD',
        'description' => 'Test order',
        'metadata' => ['order_id' => '123'],
    ], headers());

    $response->assertStatus(201);

    $payment = PaymentIntent::where('key', $response->json('data.id'))->first();
    expect($payment->description)->toBe('Test order');
    expect($payment->metadata)->toBe(['order_id' => '123']);
});

// ─── Confirm: status transition and amount_received assertions ─────────────────

test('confirm automatic capture transitions to succeeded with amount_received', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 4200,
        'currency' => 'USD',
    ], headers());
    $paymentId = $create->json('data.id');

    // Before confirm: status is requires_payment_method
    $paymentBefore = PaymentIntent::where('key', $paymentId)->first();
    expect($paymentBefore->status->value)->toBe('requires_payment_method');
    expect($paymentBefore->amount_received)->toBeNull();

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    $response->assertOk();

    // After confirm: status changed, amount_received set
    $paymentAfter = PaymentIntent::where('key', $paymentId)->first();
    expect($paymentAfter->status->value)->toBe('succeeded');
    expect($paymentAfter->amount_received)->toBe(4200);
    expect($paymentAfter->connector)->toBe('test');
});

test('confirm manual capture transitions to requires_capture without amount_received', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 8000,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], headers());
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    $response->assertOk();

    $payment = PaymentIntent::where('key', $paymentId)->first();
    expect($payment->status->value)->toBe('requires_capture');
    expect($payment->amount_received)->toBeNull();
    expect($payment->amount_capturable)->toBe(8000);
});

test('confirm creates a payment attempt record', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 3000,
        'currency' => 'USD',
    ], headers());
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    $payment = PaymentIntent::where('key', $paymentId)->first();

    $this->assertDatabaseHas('payment_attempts', [
        'payment_intent_id' => $payment->id,
        'connector' => 'test',
        'status' => 'succeeded',
        'amount' => 3000,
    ]);
});

test('confirm increments attempt_count', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 1000,
        'currency' => 'USD',
    ], headers());
    $paymentId = $create->json('data.id');

    $paymentBefore = PaymentIntent::where('key', $paymentId)->first();
    expect($paymentBefore->attempt_count)->toBe(1);

    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    $paymentAfter = PaymentIntent::where('key', $paymentId)->first();
    expect($paymentAfter->attempt_count)->toBe(2);
});

// ─── Confirm: boundary / error cases ───────────────────────────────────────────

test('confirm non-existent payment returns 404', function () {
    $response = $this->postJson('/api/v1/payments/pay_nonexistent123456789012/confirm', cardData(), headers());

    $response->assertStatus(404);
});

// ─── Capture: exact captured amount assertions ─────────────────────────────────

test('capture stores exact amount_received and zeroes amount_capturable on full capture', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 9500,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], headers());
    $paymentId = $create->json('data.id');
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 9500,
    ], headers());

    $response->assertOk();

    $payment = PaymentIntent::where('key', $paymentId)->first();
    expect($payment->status->value)->toBe('succeeded');
    expect($payment->amount_received)->toBe(9500);
    expect($payment->amount_capturable)->toBe(0);
});

test('partial capture stores correct amounts and transitions to partially_captured_and_capturable', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 10000,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], headers());
    $paymentId = $create->json('data.id');
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 3000,
    ], headers());

    $response->assertOk();

    $payment = PaymentIntent::where('key', $paymentId)->first();
    expect($payment->status->value)->toBe('partially_captured_and_capturable');
    expect($payment->amount_received)->toBe(3000);
    expect($payment->amount_capturable)->toBe(7000);
});

test('sequential partial captures accumulate amount_received', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 10000,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], headers());
    $paymentId = $create->json('data.id');
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    // First partial capture
    $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 4000,
    ], headers())->assertOk();

    $payment = PaymentIntent::where('key', $paymentId)->first();
    expect($payment->amount_received)->toBe(4000);
    expect($payment->amount_capturable)->toBe(6000);

    // Second partial capture
    $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 6000,
    ], headers())->assertOk();

    $payment->refresh();
    expect($payment->amount_received)->toBe(10000);
    expect($payment->amount_capturable)->toBe(0);
    expect($payment->status->value)->toBe('succeeded');
});

test('capture exceeding capturable amount returns 400 with specific error code', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], headers());
    $paymentId = $create->json('data.id');
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 5001,
    ], headers());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'amount_exceeds_capturable');
});

test('capture with amount zero is rejected by validation', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], headers());
    $paymentId = $create->json('data.id');
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 0,
    ], headers());

    $response->assertStatus(422);

    // Verify payment state unchanged after rejected capture
    $payment = PaymentIntent::where('key', $paymentId)->first();
    expect($payment->status->value)->toBe('requires_capture');
    expect($payment->amount_capturable)->toBe(5000);
    expect($payment->amount_received)->toBeNull();
});

test('capture on already succeeded payment returns error', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 3000,
        'currency' => 'USD',
    ], headers());
    $paymentId = $create->json('data.id');

    // Confirm with automatic capture -> succeeded
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers())->assertOk();

    $payment = PaymentIntent::where('key', $paymentId)->first();
    expect($payment->status->value)->toBe('succeeded');

    // Try to capture a succeeded payment
    $response = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'amount_to_capture' => 1000,
    ], headers());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_state_transition');
});

// ─── Cancel: assertions ────────────────────────────────────────────────────────

test('cancel transitions payment to cancelled status in DB', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 2000,
        'currency' => 'USD',
    ], headers());
    $paymentId = $create->json('data.id');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], headers());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'cancelled');

    $this->assertDatabaseHas('payment_intents', [
        'key' => $paymentId,
        'status' => 'cancelled',
    ]);
});

test('cancel already cancelled payment returns error', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 2000,
        'currency' => 'USD',
    ], headers());
    $paymentId = $create->json('data.id');

    // First cancel
    $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], headers())->assertOk();

    // Second cancel — should fail (cancelled is terminal)
    $response = $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], headers());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_state_transition');
});

test('cancel non-existent payment returns 404', function () {
    $response = $this->postJson('/api/v1/payments/pay_nonexistent123456789012/cancel', [], headers());

    $response->assertStatus(404);
});

test('cancel authorized (requires_capture) payment transitions to cancelled', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 5000,
        'currency' => 'USD',
        'capture_method' => 'manual',
    ], headers());
    $paymentId = $create->json('data.id');
    $this->postJson("/api/v1/payments/{$paymentId}/confirm", cardData(), headers());

    // Verify requires_capture before cancel
    $payment = PaymentIntent::where('key', $paymentId)->first();
    expect($payment->status->value)->toBe('requires_capture');

    $response = $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], headers());

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'cancelled');

    $payment->refresh();
    expect($payment->status->value)->toBe('cancelled');
});
