<?php

declare(strict_types=1);

$base = (string) env('OTEL_EXPORTER_OTLP_ENDPOINT', '');

return [
    'endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', $base === '' ? null : rtrim($base, '/').'/v1/traces'),
    'ratio' => (float) env('OTEL_TRACES_SAMPLER_ARG', 0.1),
    'parent_based' => true,
    'service' => env('OTEL_SERVICE_NAME', 'payswitch'),
    'namespace' => env('OTEL_SERVICE_NAMESPACE', 'billing'),
    'instance' => env('POD_NAME', gethostname()),
    'version' => env('DEPLOY_SHA', env('APP_VERSION', 'unknown')),
    'environment' => env('DEPLOYMENT_ENVIRONMENT', 'prod'),
    'request_span' => 'payswitch.request',
    'timeout' => 0.5,
];
