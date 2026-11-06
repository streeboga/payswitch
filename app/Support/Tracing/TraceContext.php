<?php

declare(strict_types=1);

namespace App\Support\Tracing;

/**
 * W3C traceparent: 00-<trace 32 hex>-<span 16 hex>-<flags 2 hex>.
 */
final readonly class TraceContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
    ) {}

    /**
     * Продолжение чужой трассы: только если родитель выбран (флаг sampled).
     */
    public static function fromHeader(?string $header, bool $sampledOnly = true): ?self
    {
        if ($header === null || preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', $header, $m) !== 1) {
            return null;
        }
        $sampled = (hexdec($m[3]) & 1) === 1;
        if (($sampledOnly && ! $sampled) || trim($m[1], '0') === '' || trim($m[2], '0') === '') {
            return null;
        }

        return new self($m[1], bin2hex(random_bytes(8)), $m[2]);
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)), bin2hex(random_bytes(8)), null);
    }
}
