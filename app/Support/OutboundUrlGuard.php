<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Общая защита от SSRF для исходящих запросов по адресам, которые задал клиент
 * (вебхуки и т. п.).
 *
 * resolve() проверяет адрес И возвращает IP, на который нужно «прибить» запрос
 * (CURLOPT_RESOLVE): так между проверкой и соединением DNS уже не подменить
 * (DNS rebinding). Вызывать на КАЖДУЮ попытку, редиректы отключать.
 */
final class OutboundUrlGuard
{
    /** Недоступные адреса: IPv4 и IPv6 в одном списке CIDR. */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/96', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
        '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var (callable(string): list<string>)|null подмена DNS в тестах */
    public static $resolver = null;

    /**
     * @return array{host: string, port: int, ip: string}|null null — адрес небезопасен
     */
    public static function resolve(string $url): ?array
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parsed = parse_url($url);
        if ($parsed === false || isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        $httpAllowed = app()->environment(['local', 'testing']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $httpAllowed)) {
            return null;
        }

        $host = strtolower(trim($parsed['host'] ?? '', '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } elseif (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1) {
            // Только настоящие имена: «2130706433», «0x7f.1» curl разберёт как IP сам.
            $ips = self::lookup($host);
        } else {
            return null;
        }

        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! self::isGlobal($ip)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isSafe(string $url): bool
    {
        return self::resolve($url) !== null;
    }

    public static function isGlobal(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $flags |= FILTER_FLAG_GLOBAL_RANGE;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        foreach (self::BLOCKED as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $packedNet = (string) inet_pton($net);
        if (strlen($packedNet) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($packedIp, 0, $bytes) !== substr($packedNet, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNet[$bytes]) & $mask);
    }
}
