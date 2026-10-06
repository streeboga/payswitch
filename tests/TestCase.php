<?php

declare(strict_types=1);

namespace Tests;

use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Никакого живого DNS: все имена «резолвятся» в публичный адрес.
        OutboundUrlGuard::$resolver = fn (string $host): array => ['93.184.216.34'];
    }
}
