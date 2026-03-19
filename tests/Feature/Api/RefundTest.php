<?php

declare(strict_types=1);

use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Support\IdGenerator;

covers(RefundService::class);

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
});

function refundHeaders(): array
{
    return ['api-key' => test()->rawKey];
}

function makeSucceededPayment(int $amount = 6540, string $currency = 'USD'): string
{
    $create = test()->postJson('/api/v1/payments', [
        'amount' => $amount,
        'currency' => $currency,
        'confirm' => true,
        'payment_method' => 'card',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
    ], refundHeaders());

    $create->assertStatus(201);

    return $create->json('data.id');
}

// ─── Create refund: exact DB assertions ────────────────────────────────────────

test('refund stores exact amount and status in DB', function () {
    $paymentId = makeSucceededPayment(5000);

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 2500,
    ], refundHeaders());

    $response->assertStatus(201);
    $refundId = $response->json('data.id');

    $refund = Refund::where('key', $refundId)->first();
    expect($refund)->not->toBeNull();
    expect($refund->amount)->toBe(2500);
    expect($refund->status->value)->toBe('succeeded');
    expect($refund->connector)->not->toBeNull();
});

test('refund currency matches payment currency', function () {
    $paymentId = makeSucceededPayment(3000, 'EUR');

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 1000,
    ], refundHeaders());

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.currency', 'EUR');

    $refundId = $response->json('data.id');
    $refund = Refund::where('key', $refundId)->first();
    expect($refund->currency)->toBe('EUR');
});

test('refund stores connector matching payment connector', function () {
    $paymentId = makeSucceededPayment();

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 1000,
    ], refundHeaders());

    $response->assertStatus(201);

    $refundId = $response->json('data.id');
    $refund = Refund::where('key', $refundId)->first();
    expect($refund->connector)->toBe('test');
});

test('refund is linked to correct payment in DB', function () {
    $paymentId = makeSucceededPayment(4000);

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 1500,
    ], refundHeaders());

    $response->assertStatus(201);

    $refundId = $response->json('data.id');
    $refund = Refund::where('key', $refundId)->first();
    $payment = PaymentIntent::where('key', $paymentId)->first();

    expect($refund->payment_intent_id)->toBe($payment->id);
    expect($refund->merchant_account_id)->toBe($payment->merchant_account_id);
});

// ─── Boundary: refund amount > payment amount ──────────────────────────────────

test('refund amount exceeding payment amount returns 400 with specific error code', function () {
    $paymentId = makeSucceededPayment(5000);

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 5001,
    ], refundHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'refund_exceeds_payment');
});

// ─── Boundary: refund on non-succeeded payment ────────────────────────────────

test('refund on requires_payment_method payment returns 400 with specific error code', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 100,
        'currency' => 'USD',
    ], refundHeaders());
    $paymentId = $create->json('data.id');

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 100,
    ], refundHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'payment_not_succeeded');
});

test('refund on cancelled payment returns 400', function () {
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 100,
        'currency' => 'USD',
    ], refundHeaders());
    $paymentId = $create->json('data.id');

    $this->postJson("/api/v1/payments/{$paymentId}/cancel", [], refundHeaders());

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 100,
    ], refundHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'payment_not_succeeded');
});

test('refund on failed payment returns 400', function () {
    // Create payment, then manually set it to failed
    $create = $this->postJson('/api/v1/payments', [
        'amount' => 100,
        'currency' => 'USD',
    ], refundHeaders());
    $paymentId = $create->json('data.id');

    PaymentIntent::where('key', $paymentId)->update(['status' => 'failed']);

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 100,
    ], refundHeaders());

    $response->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'payment_not_succeeded');
});

// ─── Boundary: multiple partial refunds ────────────────────────────────────────

test('multiple partial refunds track total correctly and reject overflow', function () {
    $paymentId = makeSucceededPayment(10000);

    // First refund: 3000
    $r1 = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 3000,
    ], refundHeaders());
    $r1->assertStatus(201);
    expect($r1->json('data.attributes.amount'))->toBe(3000);
    expect($r1->json('data.attributes.status'))->toBe('succeeded');

    // Second refund: 5000 (total 8000)
    $r2 = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 5000,
    ], refundHeaders());
    $r2->assertStatus(201);
    expect($r2->json('data.attributes.amount'))->toBe(5000);

    // Third refund: 2000 (total would be 10000 — exact limit)
    $r3 = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 2000,
    ], refundHeaders());
    $r3->assertStatus(201);

    // Fourth refund: 1 more — should fail
    $r4 = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 1,
    ], refundHeaders());
    $r4->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'refund_exceeds_payment');
});

test('refund creates webhook event', function () {
    $paymentId = makeSucceededPayment();

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => 1000,
    ], refundHeaders());

    $response->assertStatus(201);

    $this->assertDatabaseHas('webhook_events', [
        'event_type' => 'refund_succeeded',
    ]);
});

test('refund on non-existent payment returns 404', function () {
    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => 'pay_nonexistent123456789012',
        'amount' => 100,
    ], refundHeaders());

    $response->assertStatus(404);
});

test('refund with negative amount is rejected', function () {
    $paymentId = makeSucceededPayment();

    $response = $this->postJson('/api/v1/refunds', [
        'payment_id' => $paymentId,
        'amount' => -100,
    ], refundHeaders());

    $response->assertStatus(422);
});
