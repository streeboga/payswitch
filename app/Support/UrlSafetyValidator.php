<?php

declare(strict_types=1);

namespace App\Support;

final class UrlSafetyValidator
{
    public static function isSafe(string $url): bool
    {
        $parsed = parse_url($url);

        $scheme = $parsed['scheme'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        $host = $parsed['host'] ?? '';
        if (empty($host) || $host === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
