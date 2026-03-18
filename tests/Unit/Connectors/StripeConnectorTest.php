<?php

declare(strict_types=1);

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

test('getName returns stripe', function () {
    expect(stripeConnector()->getName())->toBe('stripe');
});

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
