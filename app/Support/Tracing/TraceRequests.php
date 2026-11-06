<?php

declare(strict_types=1);

namespace App\Support\Tracing;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final readonly class TraceRequests
{
    public function __construct(private SpanRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('internal/metrics', 'internal/signals', 'api/internal/metrics', 'up')) {
            return $next($request);
        }
        $parent = config('tracing.parent_based') ? $request->header('traceparent') : null;
        $span = $this->recorder->start((string) config('tracing.request_span', 'genesis.request'), 2, ['http.request.method' => $request->method()], $parent);
        $request->attributes->set('observability.span', $span?->spanId);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route();
        $attributes = ['http.response.status_code' => $response->getStatusCode()];
        if ($route instanceof Route) {
            $attributes['http.route'] = '/'.ltrim($route->uri(), '/');
        }
        $id = $request->header('X-Request-Id');
        if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) === 1) {
            $attributes['apihub.request_id'] = $id;
        }
        $this->recorder->finish($request->attributes->get('observability.span'), $response->getStatusCode() >= 500, $attributes);
        $this->recorder->flush();
    }
}
