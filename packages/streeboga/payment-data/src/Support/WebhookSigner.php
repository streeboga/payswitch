<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Support;

class WebhookSigner
{
    public static function sign(string $payload, string $key): string
    {
        return hash_hmac('sha512', $payload, $key);
    }

    public static function verify(string $payload, string $signature, string $key): bool
    {
        return hash_equals(self::sign($payload, $key), $signature);
    }
}
