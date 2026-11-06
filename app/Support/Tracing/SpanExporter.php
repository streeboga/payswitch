<?php

declare(strict_types=1);

namespace App\Support\Tracing;

use App\Support\Observability\FallbackSignals;
use App\Support\Observability\TelemetryResource;
use Illuminate\Support\Facades\Http;
use Throwable;

/** One bounded OTLP/HTTP JSON request per completed HTTP/console/queue scope. */
final readonly class SpanExporter
{
    public function __construct(private FallbackSignals $signals) {}

    /** @param list<array<string, mixed>> $spans */
    public function send(array $spans): void
    {
        $endpoint = config('tracing.endpoint');
        if (! is_string($endpoint) || $endpoint === '' || $spans === []) {
            return;
        }
        $body = ['resourceSpans' => [['resource' => ['attributes' => TelemetryResource::attributes()], 'scopeSpans' => [['scope' => ['name' => 'laravel.runtime'], 'spans' => $spans]]]]];
        try {
            $response = Http::timeout(max(0.05, min(1.0, (float) config('tracing.timeout', 0.5))))
                ->connectTimeout(0.25)->withoutRedirecting()->asJson()->post($endpoint, $body);
            if (! $response->successful() || (int) $response->json('partialSuccess.rejectedSpans', 0) > 0) {
                $this->signals->record('trace_export');
                $this->signals->gauges(['trace_export_up' => 0]);

                return;
            }
            $this->signals->succeeded('trace_export');
            $this->signals->gauges(['trace_export_up' => 1]);
        } catch (Throwable $exception) {
            $this->signals->record('trace_export', $exception);
            $this->signals->gauges(['trace_export_up' => 0]);
        }
    }
}
