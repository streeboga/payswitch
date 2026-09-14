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

    /**
     * Подпись с меткой времени (защита от повтора, С7). Подписывается
     * "{timestamp}.{payload}" — приёмник отвергает подпись со старой меткой,
     * поэтому перехваченный вебхук нельзя воспроизвести позже.
     *
     * Метка генерируется на каждую попытку доставки, а не на событие: повтор
     * payswitch — новая метка и новая подпись, а повтор злоумышленника несёт
     * старую. Живёт рядом со старой sign() ради ступенчатой миграции: payswitch
     * шлёт обе подписи, приёмник принимает новую с окном, старую — как fallback,
     * пока не выключат.
     */
    public static function signWithTimestamp(string $payload, string $key, int $timestamp): string
    {
        return hash_hmac('sha512', $timestamp.'.'.$payload, $key);
    }
}
