<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\Drivers\StripeConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Tests\TestCase;

uses(TestCase::class);

function stripeConnector(array $extra = []): StripeConnector
{
    return new StripeConnector(array_merge([
        'api_key' => 'sk_test_fake123',
        'webhook_secret' => 'whsec_test_secret',
    ], $extra));
}

function validStripeSignature(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return "t={$timestamp},v1={$signature}";
}

// ── Name ─────────────────────────────────────────────────────────────

test('getName returns stripe', function () {
    expect(stripeConnector()->getName())->toBe('stripe');
});

// ── Purchase ─────────────────────────────────────────────────────────

test('purchase creates PaymentIntent with correct params', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_test123',
            'status' => 'succeeded',
            'amount' => 5000,
            'currency' => 'usd',
        ]),
    ]);

    $result = stripeConnector()->purchase([
        'amount' => 5000,
        'currency' => 'USD',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'payment_id' => 'pay_test',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe('pi_test123');
    expect($result['code'])->toBe('ok');
});

test('purchase with token uses payment_method field', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_token', 'status' => 'succeeded']),
    ]);

    stripeConnector()->purchase([
        'amount' => 5000,
        'currency' => 'USD',
        'token' => 'pm_saved_card_123',
        'payment_id' => 'pay_token_test',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->body(), 'payment_method=pm_saved_card_123');
    });
});

test('purchase sends Idempotency-Key header', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_idem', 'status' => 'succeeded']),
    ]);

    stripeConnector()->purchase([
        'amount' => 5000,
        'currency' => 'USD',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'payment_id' => 'pay_idem_test',
    ]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Idempotency-Key')
            && $request->header('Idempotency-Key')[0] === 'pay_idem_test';
    });
});

// ── Authorize ────────────────────────────────────────────────────────

test('authorize creates PaymentIntent with manual capture', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_auth456',
            'status' => 'requires_capture',
        ]),
    ]);

    $result = stripeConnector()->authorize([
        'amount' => 10000,
        'currency' => 'USD',
        'payment_method_data' => ['card' => [
            'card_number' => '4242424242424242',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'payment_id' => 'pay_auth',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe('pi_auth456');

    Http::assertSent(function ($request) {
        return str_contains($request->body(), 'capture_method=manual');
    });
});

// ── Capture ──────────────────────────────────────────────────────────

test('capture sends correct request', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents/pi_auth456/capture' => Http::response([
            'id' => 'pi_auth456',
            'status' => 'succeeded',
        ]),
    ]);

    $result = stripeConnector()->capture([
        'amount' => 5000,
        'transaction_id' => 'pi_auth456',
    ]);

    expect($result['success'])->toBeTrue();
});

// ── Refund ───────────────────────────────────────────────────────────

test('refund sends correct request', function () {
    Http::fake([
        'api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_test789',
            'status' => 'succeeded',
        ]),
    ]);

    $result = stripeConnector()->refund([
        'amount' => 3000,
        'transaction_id' => 'pi_test123',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['transaction_id'])->toBe('re_test789');
});

// ── Error handling ───────────────────────────────────────────────────

test('handles Stripe error response', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'message' => 'Your card was declined.',
            ],
        ], 402),
    ]);

    $result = stripeConnector()->purchase([
        'amount' => 5000,
        'currency' => 'USD',
        'payment_method_data' => ['card' => [
            'card_number' => '4000000000000002',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'payment_id' => 'pay_decline',
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['code'])->toBe('card_declined');
});

test('handles server error gracefully', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response(null, 500),
    ]);

    $result = stripeConnector()->purchase([
        'amount' => 5000,
        'currency' => 'USD',
        'payment_method_data' => ['card' => []],
        'payment_id' => 'pay_err',
    ]);

    expect($result['success'])->toBeFalse();
});

// ── 3DS ──────────────────────────────────────────────────────────────

test('3DS required returns requires_action with redirect_url', function () {
    Http::fake([
        'api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_3ds',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'redirect_to_url',
                'redirect_to_url' => ['url' => 'https://stripe.com/3ds/authenticate'],
            ],
        ]),
    ]);

    $result = stripeConnector()->purchase([
        'amount' => 5000,
        'currency' => 'USD',
        'payment_method_data' => ['card' => [
            'card_number' => '4000000000003220',
            'card_exp_month' => '12',
            'card_exp_year' => '2030',
            'card_cvc' => '123',
        ]],
        'payment_id' => 'pay_3ds',
    ]);

    expect($result['code'])->toBe('requires_action');
    expect($result['data']['redirect_url'])->toBe('https://stripe.com/3ds/authenticate');
});

// ── Webhook signature ────────────────────────────────────────────────

test('verifyWebhookSignature rejects missing header', function () {
    $result = stripeConnector()->verifyWebhookSignature('{}', []);

    expect($result)->toBeFalse();
});

test('verifyWebhookSignature rejects without webhook_secret configured', function () {
    $connector = stripeConnector(['webhook_secret' => '']);

    $result = $connector->verifyWebhookSignature('{}', [
        'stripe-signature' => 't=123,v1=abc',
    ]);

    expect($result)->toBeFalse();
});

test('verifyWebhookSignature validates correct signature', function () {
    $payload = '{"type":"payment_intent.succeeded"}';
    $secret = 'whsec_test_secret';
    $header = validStripeSignature($payload, $secret);

    $result = stripeConnector()->verifyWebhookSignature($payload, [
        'stripe-signature' => $header,
    ]);

    expect($result)->toBeTrue();
});

test('verifyWebhookSignature rejects expired timestamp', function () {
    $payload = '{"type":"payment_intent.succeeded"}';
    $secret = 'whsec_test_secret';
    $expiredTimestamp = time() - 400;
    $header = validStripeSignature($payload, $secret, $expiredTimestamp);

    $result = stripeConnector()->verifyWebhookSignature($payload, [
        'stripe-signature' => $header,
    ]);

    expect($result)->toBeFalse();
});

test('verifyWebhookSignature rejects tampered payload', function () {
    $payload = '{"type":"payment_intent.succeeded"}';
    $secret = 'whsec_test_secret';
    $header = validStripeSignature($payload, $secret);

    $tampered = '{"type":"payment_intent.succeeded","extra":true}';

    $result = stripeConnector()->verifyWebhookSignature($tampered, [
        'stripe-signature' => $header,
    ]);

    expect($result)->toBeFalse();
});

// ── Webhook event mapping ────────────────────────────────────────────

test('mapWebhookEventToStatus maps all events correctly', function () {
    $c = stripeConnector();

    expect($c->mapWebhookEventToStatus('payment_intent.succeeded'))->toBe(PaymentStatus::Succeeded);
    expect($c->mapWebhookEventToStatus('payment_intent.payment_failed'))->toBe(PaymentStatus::Failed);
    expect($c->mapWebhookEventToStatus('payment_intent.canceled'))->toBe(PaymentStatus::Cancelled);
    expect($c->mapWebhookEventToStatus('payment_intent.requires_action'))->toBe(PaymentStatus::RequiresCustomerAction);
    expect($c->mapWebhookEventToStatus('unknown.event'))->toBeNull();
});

test('extractPaymentIdFromWebhook extracts from metadata and handles missing', function () {
    $c = stripeConnector();

    expect($c->extractPaymentIdFromWebhook([
        'data' => ['object' => ['metadata' => ['payment_id' => 'pay_01ABC']]],
    ]))->toBe('pay_01ABC');

    expect($c->extractPaymentIdFromWebhook(['data' => ['object' => []]]))->toBeNull();
    expect($c->extractPaymentIdFromWebhook([]))->toBeNull();
});
