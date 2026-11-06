<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Observability\FallbackSignals;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SignalsEndpointTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->file = sys_get_temp_dir().'/payswitch-signals-'.bin2hex(random_bytes(6)).'.json';
        config(['observability.file' => $this->file, 'observability.metrics_token' => 'collector-only-test', 'tracing.endpoint' => null]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_dedicated_collector_token_is_required_for_both_fake_applications(): void
    {
        foreach (['app-a-secret', 'app-b-secret'] as $key) {
            $this->withToken($key)->get('/internal/signals')->assertUnauthorized();
        }
        $body = $this->withToken('collector-only-test')->get('/internal/signals')->assertOk()->getContent();
        $this->assertStringContainsString('payswitch_telemetry_storage_up 1', $body);
        config(['observability.metrics_token' => '']);
        $this->withToken('collector-only-test')->get('/internal/signals')->assertNotFound();
    }

    public function test_file_signals_survive_cache_failure_and_do_not_export_exception_messages(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis unavailable'));
        app(FallbackSignals::class)->record('sdk_retry', new \RuntimeException('Bearer PRIVATE_SENTINEL'));
        $body = $this->withToken('collector-only-test')->get('/internal/signals')->assertOk()->getContent();
        $this->assertStringContainsString('component="sdk",operation="request",reason="connection_failed",action="retry"} 1', $body);
        $this->assertStringNotContainsString('PRIVATE_SENTINEL', $body);
    }

    public function test_fallback_retains_unsampled_parent_and_collector_failure_is_visible(): void
    {
        config(['tracing.endpoint' => 'http://collector.test/v1/traces', 'tracing.ratio' => 0]);
        Http::fake(['http://collector.test/*' => Http::response([], 503)]);
        Route::get('/signals-test/{private}', function (): array {
            app(FallbackSignals::class)->record('payment_unknown', new \RuntimeException('PRIVATE_SENTINEL'));

            return ['ok' => true];
        });

        $this->withHeader('traceparent', '00-11111111111111111111111111111111-2222222222222222-00')
            ->get('/signals-test/PRIVATE_PATH?token=PRIVATE_QUERY')->assertOk();

        Http::assertSent(function ($request): bool {
            $body = json_encode($request->data(), JSON_THROW_ON_ERROR);
            foreach (['PRIVATE_SENTINEL', 'PRIVATE_PATH', 'PRIVATE_QUERY'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }

            return $request->url() === 'http://collector.test/v1/traces'
                && str_contains($body, '11111111111111111111111111111111')
                && str_contains($body, 'fallback');
        });
        $this->assertStringContainsString('payswitch_trace_export_up 0', app(FallbackSignals::class)->render());
        $this->assertStringContainsString('component="tracing",operation="export",reason="collector_unavailable",action="degraded"} 1', app(FallbackSignals::class)->render());
    }

    public function test_scrape_is_not_traced_and_cli_exports_cumulative_metrics(): void
    {
        config(['tracing.endpoint' => 'http://collector.test/v1/traces', 'tracing.ratio' => 1]);
        Http::fake(['http://collector.test/*' => Http::response([], 200)]);
        $this->withToken('collector-only-test')->get('/internal/signals')->assertOk();
        Http::assertNothingSent();

        config(['observability.metrics_transport' => 'otlp', 'observability.metrics_endpoint' => 'http://collector.test/v1/metrics']);
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
        Artisan::command('observability:test-command', function (): void {
            app(FallbackSignals::class)->record('payment_unknown');
        });
        $this->artisan('observability:test-command')->assertSuccessful();
        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'http://collector.test/v1/metrics') {
                return false;
            }
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];
            $fallback = collect($metrics)->firstWhere('name', 'payswitch_fallback_total');
            $this->assertSame(2, $fallback['sum']['aggregationTemporality']);
            $this->assertTrue($fallback['sum']['isMonotonic']);

            return true;
        });
    }
}
