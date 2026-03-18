<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

final class ConnectorErrorNormalizer
{
    public static function normalize(string $connector, array $result): array
    {
        return [
            'type' => 'connector_error',
            'code' => $connector.'_'.($result['code'] ?? 'unknown'),
            'message' => $result['message'] ?? 'Unknown connector error',
            'connector' => $connector,
        ];
    }
}
