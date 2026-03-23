<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\SessionResultType;

test('serverRedirect creates result with correct type and data', function () {
    $result = PaymentSessionResult::serverRedirect('https://gateway.example.com/pay', 'GET', 'txn_123');

    expect($result->type)->toBe(SessionResultType::ServerRedirect);

    $array = $result->toArray();
    expect($array['type'])->toBe('redirect')
        ->and($array['url'])->toBe('https://gateway.example.com/pay')
        ->and($array['method'])->toBe('GET')
        ->and($array['transaction_id'])->toBe('txn_123');
});

test('serverRedirect defaults method to GET', function () {
    $result = PaymentSessionResult::serverRedirect('https://example.com/pay');

    $array = $result->toArray();
    expect($array['method'])->toBe('GET')
        ->and($array['transaction_id'])->toBeNull();
});

test('formRedirect creates result with correct type and data', function () {
    $result = PaymentSessionResult::formRedirect(
        'https://gateway.example.com/3ds',
        ['PaReq' => 'abc123', 'MD' => 'def456'],
        'POST',
    );

    expect($result->type)->toBe(SessionResultType::FormRedirect);

    $array = $result->toArray();
    expect($array['type'])->toBe('form_redirect')
        ->and($array['url'])->toBe('https://gateway.example.com/3ds')
        ->and($array['params'])->toBe(['PaReq' => 'abc123', 'MD' => 'def456'])
        ->and($array['method'])->toBe('POST');
});

test('embeddedWidget creates result with correct type and data', function () {
    $result = PaymentSessionResult::embeddedWidget(
        'cloudpayments',
        'https://widget.cloudpayments.ru/bundles/checkout.js',
        ['publicId' => 'pk_123', 'amount' => 1000],
        'txn_456',
    );

    expect($result->type)->toBe(SessionResultType::EmbeddedWidget);

    $array = $result->toArray();
    expect($array['type'])->toBe('widget')
        ->and($array['provider'])->toBe('cloudpayments')
        ->and($array['script_url'])->toBe('https://widget.cloudpayments.ru/bundles/checkout.js')
        ->and($array['params'])->toBe(['publicId' => 'pk_123', 'amount' => 1000])
        ->and($array['transaction_id'])->toBe('txn_456');
});

test('qrInline creates result with correct type and data', function () {
    $expiresAt = CarbonImmutable::parse('2026-04-01T12:00:00Z');

    $result = PaymentSessionResult::qrInline(
        'https://qr.nspk.ru/AS10003DFLK23SDFL',
        'url',
        'pay_abc123',
        $expiresAt,
        'txn_789',
    );

    expect($result->type)->toBe(SessionResultType::QrInline);

    $array = $result->toArray();
    expect($array['type'])->toBe('qr')
        ->and($array['qr_data'])->toBe('https://qr.nspk.ru/AS10003DFLK23SDFL')
        ->and($array['format'])->toBe('url')
        ->and($array['payment_id'])->toBe('pay_abc123')
        ->and($array['expires_at'])->toBe('2026-04-01T12:00:00Z')
        ->and($array['transaction_id'])->toBe('txn_789');
});

test('qrInline works without optional parameters', function () {
    $result = PaymentSessionResult::qrInline(
        'data:image/png;base64,ABC',
        'base64',
        'pay_xyz',
    );

    $array = $result->toArray();
    expect($array['expires_at'])->toBeNull()
        ->and($array['transaction_id'])->toBeNull();
});

test('toArray returns only type-specific fields for serverRedirect', function () {
    $result = PaymentSessionResult::serverRedirect('https://example.com');

    $array = $result->toArray();
    expect($array)->toHaveKeys(['type', 'url', 'method', 'transaction_id'])
        ->and($array)->not->toHaveKeys(['params', 'provider', 'script_url', 'qr_data', 'format', 'payment_id', 'expires_at']);
});

test('toArray returns only type-specific fields for formRedirect', function () {
    $result = PaymentSessionResult::formRedirect('https://example.com', ['key' => 'val']);

    $array = $result->toArray();
    expect($array)->toHaveKeys(['type', 'url', 'params', 'method'])
        ->and($array)->not->toHaveKeys(['transaction_id', 'provider', 'script_url', 'qr_data', 'format', 'payment_id', 'expires_at']);
});

test('toArray returns only type-specific fields for embeddedWidget', function () {
    $result = PaymentSessionResult::embeddedWidget('cp', 'https://js.example.com', []);

    $array = $result->toArray();
    expect($array)->toHaveKeys(['type', 'provider', 'script_url', 'params', 'transaction_id'])
        ->and($array)->not->toHaveKeys(['url', 'method', 'qr_data', 'format', 'payment_id', 'expires_at']);
});

test('toArray returns only type-specific fields for qrInline', function () {
    $result = PaymentSessionResult::qrInline('data', 'url', 'pay_1');

    $array = $result->toArray();
    expect($array)->toHaveKeys(['type', 'qr_data', 'format', 'payment_id', 'expires_at', 'transaction_id'])
        ->and($array)->not->toHaveKeys(['url', 'method', 'params', 'provider', 'script_url']);
});
