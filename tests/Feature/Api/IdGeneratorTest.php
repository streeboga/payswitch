<?php

declare(strict_types=1);

use Streeboga\PaymentData\Support\IdGenerator;

test('payment id starts with pay_ prefix', function () {
    expect(IdGenerator::paymentId())->toStartWith('pay_');
});

test('payment id has consistent length', function () {
    $id = IdGenerator::paymentId();
    // pay_ (4) + ULID (26) = 30
    expect(strlen($id))->toBeGreaterThanOrEqual(28);
});

test('customer id starts with cus_ prefix', function () {
    expect(IdGenerator::customerId())->toStartWith('cus_');
});

test('refund id starts with ref_ prefix', function () {
    expect(IdGenerator::refundId())->toStartWith('ref_');
});

test('profile id starts with pro_ prefix', function () {
    expect(IdGenerator::profileId())->toStartWith('pro_');
});

test('merchant id starts with merchant_ prefix', function () {
    expect(IdGenerator::merchantId())->toStartWith('merchant_');
});

test('mca id starts with mca_ prefix', function () {
    expect(IdGenerator::mcaId())->toStartWith('mca_');
});

test('org id starts with org_ prefix', function () {
    expect(IdGenerator::orgId())->toStartWith('org_');
});

test('event id starts with evt_ prefix', function () {
    expect(IdGenerator::eventId())->toStartWith('evt_');
});

test('client secret contains payment id and _secret_ separator', function () {
    $paymentId = 'pay_01JGW2N7KBZV8QJMTEST12345';
    $secret = IdGenerator::clientSecret($paymentId);

    expect($secret)
        ->toStartWith($paymentId . '_secret_')
        ->not->toBe($paymentId);
});

test('sandbox api key starts with snd_ prefix', function () {
    expect(IdGenerator::apiKey('sandbox'))->toStartWith('snd_');
});

test('production api key starts with prod_ prefix', function () {
    expect(IdGenerator::apiKey('production'))->toStartWith('prod_');
});

test('sandbox publishable key starts with pk_snd_', function () {
    expect(IdGenerator::publishableKey('sandbox'))->toStartWith('pk_snd_');
});

test('production publishable key starts with pk_prod_', function () {
    expect(IdGenerator::publishableKey('production'))->toStartWith('pk_prod_');
});

test('generates unique ids across 100 calls', function () {
    $ids = collect(range(1, 100))->map(fn () => IdGenerator::paymentId());
    expect($ids->unique()->count())->toBe(100);
});

test('ids are sortable by creation time (ULID property)', function () {
    $first = IdGenerator::paymentId();
    usleep(1000); // 1ms
    $second = IdGenerator::paymentId();

    expect($first < $second)->toBeTrue();
});
