<?php

declare(strict_types=1);

namespace App\Support;

/** Хэш запроса, не зависящий от порядка ключей: {"a":1,"b":2} и {"b":2,"a":1} — один запрос. */
final class CanonicalRequest
{
    /** @param array<array-key, mixed> $value */
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::sort($value), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? self::sort($item) : $item, $value);
    }
}
