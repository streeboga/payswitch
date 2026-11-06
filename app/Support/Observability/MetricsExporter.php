<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** OTLP for CLI pods: web scrape cannot read a different pod's local file. */
final class MetricsExporter
{
    private bool $exporting = false;

    public function __construct(private readonly FallbackSignals $signals) {}

    public function exporting(): bool
    {
        return $this->exporting;
    }

    public function flush(): void
    {
        $endpoint = config('observability.metrics_endpoint');
        if ($this->exporting || config('observability.metrics_transport') !== 'otlp' || ! is_string($endpoint) || $endpoint === '' || DB::transactionLevel() > 0) {
            return;
        }
        $this->exporting = true;
        try {
            $body = ['resourceMetrics' => [['resource' => ['attributes' => TelemetryResource::attributes()], 'scopeMetrics' => [['scope' => ['name' => 'laravel.runtime'], 'metrics' => $this->metrics()]]]]];
            $response = Http::timeout(0.5)->connectTimeout(0.25)->withoutRedirecting()->asJson()->post($endpoint, $body);
            if (! $response->successful() || (int) $response->json('partialSuccess.rejectedDataPoints', 0) > 0) {
                $this->signals->record('metrics_export');
                $this->signals->gauges(['metrics_export_up' => 0]);

                return;
            }
            $this->signals->succeeded('metrics_export');
            $this->signals->gauges(['metrics_export_up' => 1]);
        } catch (Throwable $exception) {
            $this->signals->record('metrics_export', $exception);
            $this->signals->gauges(['metrics_export_up' => 0]);
        } finally {
            $this->exporting = false;
        }
    }

    /**
     * Convert our own bounded integer exposition so HTTP scrape and CLI push
     * share the exact names/values; no database or Redis query on this path.
     *
     * @return list<array<string, mixed>>
     */
    private function metrics(): array
    {
        $metrics = [];
        $now = (string) (int) (microtime(true) * 1e9);
        $started = (string) ($this->signals->startedAt() * 1000000000);
        foreach (explode("\n", $this->signals->render()) as $line) {
            if (preg_match('/^([a-z][a-z0-9_]*)(?:\{([^}]+)\})? (-?\d+)$/', $line, $match) !== 1) {
                continue;
            }
            $labels = [];
            preg_match_all('/([a-z_]+)="([a-z0-9_]+)"/', $match[2], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $labels[] = ['key' => $pair[1], 'value' => ['stringValue' => $pair[2]]];
            }
            $point = ['attributes' => $labels, 'timeUnixNano' => $now, 'asInt' => $match[3]];
            $counter = str_ends_with($match[1], '_total');
            if ($counter) {
                $point['startTimeUnixNano'] = $started;
            }
            $type = $counter ? 'sum' : 'gauge';
            $metrics[$match[1]] ??= ['name' => $match[1], $type => ['dataPoints' => []] + ($counter ? ['aggregationTemporality' => 2, 'isMonotonic' => true] : [])];
            $metrics[$match[1]][$type]['dataPoints'][] = $point;
        }

        return array_values($metrics);
    }
}
