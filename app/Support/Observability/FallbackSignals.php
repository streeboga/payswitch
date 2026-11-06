<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Pod-local counters survive PHP requests without depending on Redis or SQL. */
final class FallbackSignals
{
    private bool $storageFailed = false;

    public function record(string $event, ?Throwable $exception = null): void
    {
        $definition = config('observability.events.'.$event);
        if (! is_array($definition) || count($definition) !== 4) {
            $this->storageFailure('invalid_definition');

            return;
        }
        [$component, $operation, $reason, $action] = $definition;
        $context = ['event' => 'fallback', 'component' => $component, 'operation' => $operation, 'reason' => $reason, 'action' => $action];
        if ($exception !== null) {
            $context['error_type'] = $exception::class;
        }
        $this->update(function (array $state) use ($event): array {
            $state['events'][$event] = (int) ($state['events'][$event] ?? 0) + 1;

            return $state;
        });
        try {
            if (in_array($event, config('observability.notice_events', []), true)) {
                Log::notice('fallback', $context);
            } else {
                Log::warning('fallback', $context);
            }
            Event::dispatch('observability.fallback', [$context]);
        } catch (Throwable) {
            error_log(json_encode(['event' => 'telemetry_export_failed', 'component' => 'logging', 'reason' => 'delivery_failed', 'action' => 'degraded'], JSON_THROW_ON_ERROR));
        }
    }

    public function succeeded(string $operation): void
    {
        if (! in_array($operation, config('observability.operations', []), true)) {
            return;
        }
        $this->update(function (array $state) use ($operation): array {
            $state['success'][$operation] = time();

            return $state;
        });
    }

    /** @param array<string, int> $gauges */
    public function gauges(array $gauges): void
    {
        $allowed = array_intersect_key($gauges, array_flip(config('observability.gauges', [])));
        $this->update(fn (array $state): array => array_replace_recursive($state, ['gauges' => $allowed]));
    }

    public function render(): string
    {
        $prefix = (string) config('observability.prefix');
        $state = [];
        $path = (string) config('observability.file');
        if (is_file($path)) {
            try {
                $state = $this->locked(false);
            } catch (Throwable) {
                $this->storageFailure('read_failed');
            }
            if (! is_writable($path)) {
                $this->storageFailure('write_denied');
            }
        } elseif (! is_writable(dirname($path))) {
            $this->storageFailure('directory_unavailable');
        }
        $out = ["# TYPE {$prefix}_fallback_total counter"];
        foreach (config('observability.events', []) as $event => [$component, $operation, $reason, $action]) {
            $labels = 'component="'.$component.'",operation="'.$operation.'",reason="'.$reason.'",action="'.$action.'"';
            $out[] = $prefix.'_fallback_total{'.$labels.'} '.(int) ($state['events'][$event] ?? 0);
        }
        $out[] = "# TYPE {$prefix}_telemetry_storage_up gauge";
        $out[] = $prefix.'_telemetry_storage_up '.(int) ! $this->storageFailed;
        $out[] = $prefix.'_tracing_enabled '.(int) (bool) config('tracing.endpoint');
        foreach ($state['success'] ?? [] as $operation => $at) {
            if (in_array($operation, config('observability.operations', []), true)) {
                $out[] = $prefix.'_operation_last_success_timestamp_seconds{operation="'.$operation.'"} '.(int) $at;
                $out[] = $prefix.'_operation_last_success_age_seconds{operation="'.$operation.'"} '.max(0, time() - (int) $at);
            }
        }
        foreach ($state['gauges'] ?? [] as $name => $value) {
            if (in_array($name, config('observability.gauges', []), true)) {
                $out[] = $prefix.'_'.$name.' '.(int) $value;
            }
        }

        return implode("\n", $out)."\n";
    }

    public function startedAt(): int
    {
        try {
            return (int) ($this->locked(false)['started_at'] ?? time());
        } catch (Throwable) {
            return time();
        }
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $change */
    private function update(\Closure $change): void
    {
        try {
            $this->locked(true, $change);
        } catch (Throwable) {
            $this->storageFailure('write_failed');
        }
    }

    /**
     * @param  (\Closure(array<string, mixed>): array<string, mixed>)|null  $change
     * @return array<string, mixed>
     */
    private function locked(bool $write, ?\Closure $change = null): array
    {
        $handle = @fopen((string) config('observability.file'), $write ? 'c+' : 'r');
        if ($handle === false) {
            throw new RuntimeException('Telemetry file unavailable');
        }
        try {
            if (! flock($handle, $write ? LOCK_EX : LOCK_SH)) {
                throw new RuntimeException('Telemetry lock unavailable');
            }
            $raw = stream_get_contents($handle);
            $state = $raw === '' ? [] : json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($state)) {
                throw new RuntimeException('Invalid telemetry state');
            }
            if ($change !== null) {
                $state['started_at'] ??= time();
                $state = $change($state);
                $json = json_encode($state, JSON_THROW_ON_ERROR);
                rewind($handle);
                if (! ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new RuntimeException('Telemetry write failed');
                }
            }

            return $state;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function storageFailure(string $reason): void
    {
        if (! $this->storageFailed) {
            $context = ['event' => 'telemetry_export_failed', 'component' => 'signals', 'operation' => 'store', 'reason' => $reason, 'action' => 'degraded'];
            try {
                Log::error('telemetry_export_failed', $context);
            } catch (Throwable) {
                error_log(json_encode($context, JSON_THROW_ON_ERROR));
            }
        }
        $this->storageFailed = true;
    }
}
