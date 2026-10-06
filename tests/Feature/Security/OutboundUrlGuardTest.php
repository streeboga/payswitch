<?php

declare(strict_types=1);

use App\Support\OutboundUrlGuard;

beforeEach(function () {
    OutboundUrlGuard::$resolver = fn (string $host): array => match ($host) {
        'rebind.example.com' => ['93.184.216.34', '10.0.0.5'],
        'v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946'],
        'v6local.example.com' => ['::1'],
        default => ['93.184.216.34'],
    };
});

test('accepts public https and pins the resolved ip', function () {
    expect(OutboundUrlGuard::resolve('https://hook.example.com:8443/x'))
        ->toBe(['host' => 'hook.example.com', 'port' => 8443, 'ip' => '93.184.216.34']);
    expect(OutboundUrlGuard::isSafe('https://v6.example.com/'))->toBeTrue();
});

test('rejects non-global addresses', function (string $url) {
    expect(OutboundUrlGuard::isSafe($url))->toBeFalse();
})->with([
    'http://[::1]/', 'https://[::1]/', 'https://127.0.0.1/', 'https://10.1.2.3/', 'https://192.168.0.1/',
    'https://169.254.169.254/latest/meta-data', 'https://100.64.0.1/', 'https://0.0.0.0/', 'https://240.0.0.1/',
    'https://198.18.0.1/', 'https://192.0.0.8/', 'https://[fc00::1]/', 'https://[fe80::1]/',
    'https://[::ffff:127.0.0.1]/', 'https://[64:ff9b::7f00:1]/', 'https://[2002:7f00:1::]/', 'https://[::127.0.0.1]/',
    'https://localhost/', 'https://a.localhost/', 'https://v6local.example.com/',
    'https://rebind.example.com/',
]);

test('rejects odd url shapes', function (string $url) {
    expect(OutboundUrlGuard::isSafe($url))->toBeFalse();
})->with([
    'https://user:pw@hook.example.com/', 'https://hook.example.com\\@127.0.0.1/', "https://hook.example.com/\r\nX: y",
    'https://hook.example.com /x', 'ftp://hook.example.com/', 'file:///etc/passwd', 'https://2130706433/', 'https://0x7f.1/',
    'https:///x', '',
]);

test('plain http is only for local and testing', function () {
    expect(OutboundUrlGuard::isSafe('http://hook.example.com/'))->toBeTrue(); // testing env
    app()->detectEnvironment(fn () => 'production');
    expect(OutboundUrlGuard::isSafe('http://hook.example.com/'))->toBeFalse();
});

test('unresolvable host is rejected', function () {
    OutboundUrlGuard::$resolver = fn (): array => [];
    expect(OutboundUrlGuard::isSafe('https://nxdomain.example.com/'))->toBeFalse();
});
