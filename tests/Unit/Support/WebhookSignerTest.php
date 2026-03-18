<?php

declare(strict_types=1);

use Streeboga\PaymentData\Support\WebhookSigner;

test('signs payload with HMAC-SHA512', function () {
    $payload = '{"event_type":"payment_succeeded","payment_id":"pay_01J123"}';
    $key = 'test_hash_key_64_characters_long_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    $signature = WebhookSigner::sign($payload, $key);

    expect($signature)->toBe(hash_hmac('sha512', $payload, $key));
});

test('produces hex string signature', function () {
    $signature = WebhookSigner::sign('test', 'key');

    expect($signature)->toMatch('/^[a-f0-9]+$/');
});

test('verifies valid signature returns true', function () {
    $payload = '{"event":"test"}';
    $key = 'secret_key_123';
    $signature = hash_hmac('sha512', $payload, $key);

    expect(WebhookSigner::verify($payload, $signature, $key))->toBeTrue();
});

test('verifies invalid signature returns false', function () {
    expect(WebhookSigner::verify('payload', 'invalid_signature', 'key'))->toBeFalse();
});

test('verifies tampered payload returns false', function () {
    $key = 'secret';
    $signature = WebhookSigner::sign('original', $key);

    expect(WebhookSigner::verify('tampered', $signature, $key))->toBeFalse();
});

test('different keys produce different signatures', function () {
    $payload = 'same_payload';

    $sig1 = WebhookSigner::sign($payload, 'key1');
    $sig2 = WebhookSigner::sign($payload, 'key2');

    expect($sig1)->not->toBe($sig2);
});
