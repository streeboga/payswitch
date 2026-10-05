<?php

declare(strict_types=1);

use App\Support\MoneyConverter;
use Streeboga\PaymentData\Exceptions\PaymentException;

covers(MoneyConverter::class);

test('RUB ledger units convert exactly once to kopecks', function () {
    expect(MoneyConverter::atomicToMinor('1000000', 'RUB', 8, 2))->toBe(1)
        ->and(MoneyConverter::atomicToMinor('12300000000', 'RUB', 8, 2))->toBe(12300)
        ->and(MoneyConverter::atomicToMinor('12300', 'RUB', 2, 8))->toBe(12300000000)
        ->and(MoneyConverter::majorToMinor('123.45'))->toBe(12345)
        ->and(MoneyConverter::minorToMajor(12345))->toBe('123.45');
});

test('fractional residue overflow and malformed units are rejected', function (string $amount, int $precision) {
    expect(fn () => MoneyConverter::atomicToMinor($amount, 'RUB', $precision, 2))->toThrow(PaymentException::class);
})->with([['1000001', 8], [(string) PHP_INT_MAX.'0', 2], ['-1', 2], ['1e8', 8], ['1.5', 2], ['1', 19]]);

test('decimal fractions cannot silently round up to a kopeck', function () {
    expect(fn () => MoneyConverter::majorToMinor('0.009'))->toThrow(PaymentException::class);
    expect(MoneyConverter::atomicToMinor((string) PHP_INT_MAX, 'RUB', 2, 2))->toBe(PHP_INT_MAX);
});
