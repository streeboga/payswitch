<?php

declare(strict_types=1);

namespace App\Support\Tracing;

use App\Support\Observability\FallbackSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SpanRecorder
{
    /** @var array<string, array{context: TraceContext, name: string, kind: int, at: float, attributes: array<string, string|int>, events: list<array<string, mixed>>}> */
    private array $active = [];

    /** @var list<string> */
    private array $stack = [];

    /** @var list<array<string, mixed>> */
    private array $finished = [];

    private bool $exporting = false;

    private bool $dropped = false;

    /** @var array<string, bool> */
    private array $sampled = [];

    public function __construct(private readonly SpanExporter $exporter, private readonly FallbackSignals $signals) {}

    /** @param array<string, string|int> $attributes */
    public function start(string $name, int $kind = 1, array $attributes = [], ?string $parent = null): ?TraceContext
    {
        if ($this->exporting || ! config('tracing.endpoint')) {
            return null;
        }
        if (count($this->active) >= 128) {
            if (! $this->dropped) {
                $this->dropped = true;
                $this->signals->record('trace_buffer');
            }

            return null;
        }
        $current = $this->current();
        $context = $parent === null && $current !== null
            ? new TraceContext($current->traceId, bin2hex(random_bytes(8)), $current->spanId)
            : TraceContext::fromHeader($parent, false);
        if ($context === null) {
            $ratio = max(0.0, min(1.0, (float) config('tracing.ratio', 0)));
            $context = TraceContext::generate();
            $this->sampled[$context->traceId] = $ratio > 0 && random_int(0, PHP_INT_MAX) / PHP_INT_MAX < $ratio;
        } elseif ($parent !== null && (hexdec(substr($parent, -2)) & 1) === 1) {
            $this->sampled[$context->traceId] = true;
        } elseif (! isset($this->sampled[$context->traceId])) {
            $this->sampled[$context->traceId] = false;
        }
        $this->active[$context->spanId] = ['context' => $context, 'name' => $name, 'kind' => $kind, 'at' => microtime(true), 'attributes' => $attributes, 'events' => []];
        $this->stack[] = $context->spanId;
        $this->shareContext();

        return $context;
    }

    /** @param array<string, string|int> $attributes */
    public function finish(?string $id, bool $failed = false, array $attributes = []): void
    {
        if ($id === null || ! isset($this->active[$id])) {
            return;
        }
        // A client middleware can throw before Laravel emits ConnectionFailed.
        // Close its unfinished children with the owning runtime scope.
        if ($this->active[$id]['kind'] !== 3) {
            foreach ($this->active as $childId => $child) {
                if ($child['context']->parentSpanId === $id) {
                    $this->finish($childId, true);
                }
            }
        }
        $span = $this->active[$id];
        unset($this->active[$id]);
        $this->stack = array_values(array_filter($this->stack, fn (string $entry): bool => $entry !== $id));
        $context = $span['context'];
        $out = ['traceId' => $context->traceId, 'spanId' => $id, 'name' => $span['name'], 'kind' => $span['kind'],
            'startTimeUnixNano' => (string) (int) ($span['at'] * 1e9), 'endTimeUnixNano' => (string) (int) (microtime(true) * 1e9),
            'attributes' => $this->attributes($attributes + $span['attributes']), 'events' => $span['events']];
        if ($context->parentSpanId !== null) {
            $out['parentSpanId'] = $context->parentSpanId;
        }
        if ($failed) {
            $this->sampled[$context->traceId] = true;
            $out['status'] = ['code' => 2];
        }
        if (count($this->finished) < 128) {
            $this->finished[] = $out;
        } elseif ($this->stack === []) {
            // Keep the owning scope when the bounded client buffer is full.
            $this->finished[count($this->finished) - 1] = $out;
        } elseif (! $this->dropped) {
            $this->dropped = true;
            $this->signals->record('trace_buffer');
        }
        $this->shareContext();
    }

    /** @param array<string, string> $context */
    public function fallback(array $context): void
    {
        $current = $this->current();
        if ($current !== null && count($this->active[$current->spanId]['events']) < 32) {
            $this->sampled[$current->traceId] = true;
            $this->active[$current->spanId]['events'][] = ['name' => 'fallback', 'timeUnixNano' => (string) (int) (microtime(true) * 1e9), 'attributes' => $this->attributes($context)];
        }
    }

    public function header(): ?string
    {
        $current = $this->current();

        return $current === null ? null : '00-'.$current->traceId.'-'.$current->spanId.($this->sampled[$current->traceId] ? '-01' : '-00');
    }

    public function exporting(): bool
    {
        return $this->exporting;
    }

    public function flush(): void
    {
        if ($this->exporting || $this->finished === [] || DB::transactionLevel() > 0) {
            return;
        }
        $batch = array_values(array_filter($this->finished, fn (array $span): bool => $this->sampled[$span['traceId']] ?? false));
        $this->finished = $this->active === [] ? [] : array_values(array_filter($this->finished, fn (array $span): bool => ! ($this->sampled[$span['traceId']] ?? false)));
        if ($this->active === []) {
            $this->sampled = [];
        }
        if ($batch === []) {
            return;
        }
        $this->dropped = false;
        $this->exporting = true;
        try {
            Log::withContext(['trace_id' => $batch[0]['traceId'], 'span_id' => $batch[0]['spanId']]);
            $this->exporter->send($batch);
        } finally {
            $this->exporting = false;
            $this->shareContext();
        }
    }

    private function current(): ?TraceContext
    {
        $last = end($this->stack);

        return $last === false ? null : $this->active[$last]['context'];
    }

    private function shareContext(): void
    {
        $current = $this->current();
        Log::withoutContext(['trace_id', 'span_id']);
        if ($current !== null) {
            Log::withContext(['trace_id' => $current->traceId, 'span_id' => $current->spanId]);
        }
    }

    /** @param array<string, string|int> $attributes
     * @return list<array{key: string, value: array<string, string>}>
     */
    private function attributes(array $attributes): array
    {
        $allowed = ['http.request.method', 'http.route', 'http.response.status_code', 'apihub.request_id', 'console.command', 'process.exit_code', 'messaging.operation.name', 'event', 'component', 'operation', 'reason', 'action', 'error_type'];
        $out = [];
        foreach ($attributes as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $out[] = ['key' => $key, 'value' => is_int($value) ? ['intValue' => (string) $value] : ['stringValue' => substr($value, 0, 128)]];
            }
        }

        return $out;
    }
}
