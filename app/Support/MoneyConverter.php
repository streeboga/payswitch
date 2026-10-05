<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Streeboga\PaymentData\Exceptions\PaymentException;

final class MoneyConverter
{
    /** Convert atomic units exactly; fractional residues are rejected, never silently truncated. */
    public static function atomicToMinor(string $units, string $currency, int $sourcePrecision, int $targetPrecision): int
    {
        if (! preg_match('/^[A-Z]{3}$/D', $currency) || ! preg_match('/^[0-9]+$/D', $units)
            || $sourcePrecision < 0 || $sourcePrecision > 18 || $targetPrecision < 0 || $targetPrecision > 18) {
            throw new PaymentException('Invalid money units or precision', 'invalid_money', 'invalid_request_error', 400);
        }
        try {
            $factor = BigInteger::of(10)->power(abs($sourcePrecision - $targetPrecision));
            $value = BigInteger::of($units);
            $value = $sourcePrecision >= $targetPrecision ? $value->dividedBy($factor) : $value->multipliedBy($factor);

            return $value->toInt();
        } catch (MathException) {
            throw new PaymentException('Money has fractional residue or exceeds integer range', 'money_precision_or_overflow', 'invalid_request_error', 400);
        }
    }

    public static function majorToMinor(string $amount, int $precision = 2): int
    {
        if (! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $amount) || $precision < 0 || $precision > 18) {
            throw new PaymentException('Invalid decimal money', 'invalid_money', 'invalid_request_error', 400);
        }
        try {
            return BigDecimal::of($amount)->multipliedBy(BigInteger::of(10)->power($precision))->toBigInteger()->toInt();
        } catch (MathException) {
            throw new PaymentException('Money has fractional residue or exceeds integer range', 'money_precision_or_overflow', 'invalid_request_error', 400);
        }
    }

    public static function minorToMajor(int $amount, int $precision = 2): string
    {
        return (string) BigDecimal::of($amount)->dividedBy(BigInteger::of(10)->power($precision), $precision);
    }
}
