<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Observability\FallbackSignals;
use App\Support\Observability\MetricsExporter;
use App\Support\Tracing\SpanRecorder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;

final class ObservabilityServiceProvider extends ServiceProvider
{
    private static bool $payloadRegistered = false;

    public function register(): void
    {
        $this->app->singleton(FallbackSignals::class);
        $this->app->singleton(SpanRecorder::class);
        $this->app->singleton(MetricsExporter::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/signals.php');
        $recorder = fn (): SpanRecorder => $this->app->make(SpanRecorder::class);
        Event::listen('observability.fallback', fn (array $context) => $recorder()->fallback($context));
        Event::listen('observability.sdk_retry', fn () => $this->app->make(FallbackSignals::class)->record('sdk_retry'));

        $this->app->make(Factory::class)->globalRequestMiddleware(function (RequestInterface $request) use ($recorder): RequestInterface {
            if ($this->app->make(MetricsExporter::class)->exporting()) {
                return $request;
            }
            $span = $recorder()->start('http.client', 3, ['http.request.method' => $request->getMethod()]);

            return $span === null ? $request : $request->withHeader('traceparent', (string) $recorder()->header());
        });
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) use ($recorder): void {
            if ($recorder()->exporting() || $this->app->make(MetricsExporter::class)->exporting()) {
                return;
            }
            if ($event->response->status() >= 500) {
                $this->app->make(FallbackSignals::class)->record('sdk_rejected');
            }
            $parent = $event->request->header('traceparent')[0] ?? '';
            $recorder()->finish($this->spanId($parent), $event->response->serverError(), ['http.response.status_code' => $event->response->status()]);
        });
        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event) use ($recorder): void {
            if (! $recorder()->exporting() && ! $this->app->make(MetricsExporter::class)->exporting()) {
                $this->app->make(FallbackSignals::class)->record('sdk_connection', $event->exception);
                $recorder()->finish($this->spanId($event->request->header('traceparent')[0] ?? ''), true);
            }
        });

        $commands = [];
        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($recorder, &$commands): void {
            if (in_array($event->command, ['queue:work', 'queue:listen', 'horizon', 'schedule:work'], true)) {
                return;
            }
            $commands[$event->command][] = $recorder()->start('console.command', 1, ['console.command' => $event->command])?->spanId;
        });
        Event::listen(CommandFinished::class, function (CommandFinished $event) use ($recorder, &$commands): void {
            $id = isset($commands[$event->command]) ? array_pop($commands[$event->command]) : null;
            $recorder()->finish($id, $event->exitCode !== 0, ['process.exit_code' => $event->exitCode]);
            $recorder()->flush();
            $this->app->make(MetricsExporter::class)->flush();
        });

        $jobs = [];
        if (! self::$payloadRegistered) {
            Queue::createPayloadUsing(static fn (): array => ($parent = app(SpanRecorder::class)->header()) === null ? [] : ['traceparent' => $parent]);
            self::$payloadRegistered = true;
        }
        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($recorder, &$jobs): void {
            $parent = $event->job->payload()['traceparent'] ?? null;
            $jobs[spl_object_id($event->job)] = $recorder()->start('queue.process', 4, ['messaging.operation.name' => 'process'], is_string($parent) ? $parent : null)?->spanId;
        });
        foreach ([JobProcessed::class, JobExceptionOccurred::class] as $type) {
            Event::listen($type, function (JobProcessed|JobExceptionOccurred $event) use ($recorder, &$jobs): void {
                $key = spl_object_id($event->job);
                if ($event instanceof JobExceptionOccurred) {
                    $this->app->make(FallbackSignals::class)->record('queue_retry', $event->exception);
                }
                $recorder()->finish($jobs[$key] ?? null, $event instanceof JobExceptionOccurred);
                unset($jobs[$key]);
                $recorder()->flush();
                $this->app->make(MetricsExporter::class)->flush();
            });
        }
        $this->app->terminating(fn () => $recorder()->flush());
    }

    private function spanId(string $header): ?string
    {
        return preg_match('/^00-[0-9a-f]{32}-([0-9a-f]{16})-[0-9a-f]{2}$/', $header, $match) === 1 ? $match[1] : null;
    }
}
