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

    /**
     * Исход операции у провайдера неизвестен — это не отказ.
     *
     * Отказ считается явным, только когда провайдер сам ответил «нет» со своим кодом.
     * connector_error / connector_exception — исключение или таймаут (драйверы их
     * глотают в этот код); пустой код и error — ответ не разобран (5xx, HTML, пустое
     * тело); pending / processing — провайдер принял, но ещё не решил. В этих случаях
     * деньги могли уйти: ни повторять у другого провайдера, ни писать failed нельзя.
     *
     * @param  array<string, mixed>  $result
     */
    public static function isIndeterminate(array $result): bool
    {
        if (($result['success'] ?? false) === true) {
            return false;
        }

        return in_array((string) ($result['code'] ?? ''), ['', 'connector_error', 'connector_exception', 'error', 'unknown', 'pending', 'processing'], true);
    }
}
