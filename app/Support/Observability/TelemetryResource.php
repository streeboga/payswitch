<?php

declare(strict_types=1);

namespace App\Support\Observability;

final class TelemetryResource
{
    /** @return list<array{key: string, value: array{stringValue: string}}> */
    public static function attributes(): array
    {
        $resource = [];
        foreach (['service.name' => 'service', 'service.namespace' => 'namespace', 'service.instance.id' => 'instance', 'service.version' => 'version', 'deployment.environment' => 'environment'] as $key => $setting) {
            $value = config('tracing.'.$setting);
            if (is_string($value) && $value !== '') {
                $resource[] = ['key' => $key, 'value' => ['stringValue' => $value]];
            }
        }

        return $resource;
    }
}
