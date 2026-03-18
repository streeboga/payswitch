<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Contracts\ConnectorInterface;

final class CloudPaymentsConnector implements ConnectorInterface
{
    private string $publicId;

    private string $apiSecret;

    private string $baseUrl = 'https://api.cloudpayments.ru';

    public function __construct(array $credentials)
    {
        $this->publicId = $credentials['public_id'] ?? '';
        $this->apiSecret = $credentials['api_secret'] ?? $credentials['api_key'] ?? '';
    }

    public function getName(): string
    {
        return 'cloudpayments';
    }

    public function purchase(array $params): array
    {
        return $this->makeRequest('/payments/cards/charge', [
            'Amount' => ($params['amount'] ?? 0) / 100,
            'Currency' => $params['currency'] ?? 'RUB',
            'CardCryptogramPacket' => $params['payment_method_data']['card']['cryptogram'] ?? $params['token'] ?? '',
            'Name' => $params['payment_method_data']['card']['card_holder_name'] ?? '',
            'IpAddress' => $params['ip_address'] ?? '127.0.0.1',
            'Description' => $params['description'] ?? '',
            'InvoiceId' => $params['payment_id'] ?? '',
            'JsonData' => json_encode($params['metadata'] ?? []),
        ]);
    }

    public function authorize(array $params): array
    {
        return $this->makeRequest('/payments/cards/auth', [
            'Amount' => ($params['amount'] ?? 0) / 100,
            'Currency' => $params['currency'] ?? 'RUB',
            'CardCryptogramPacket' => $params['payment_method_data']['card']['cryptogram'] ?? $params['token'] ?? '',
            'Name' => $params['payment_method_data']['card']['card_holder_name'] ?? '',
            'IpAddress' => $params['ip_address'] ?? '127.0.0.1',
            'Description' => $params['description'] ?? '',
            'InvoiceId' => $params['payment_id'] ?? '',
        ]);
    }

    public function capture(array $params): array
    {
        return $this->makeRequest('/payments/confirm', [
            'TransactionId' => $params['transaction_id'] ?? '',
            'Amount' => ($params['amount'] ?? 0) / 100,
        ]);
    }

    public function refund(array $params): array
    {
        return $this->makeRequest('/payments/refund', [
            'TransactionId' => $params['transaction_id'] ?? '',
            'Amount' => ($params['amount'] ?? 0) / 100,
        ]);
    }

    private function makeRequest(string $endpoint, array $data): array
    {
        try {
            $response = Http::withBasicAuth($this->publicId, $this->apiSecret)
                ->timeout(30)
                ->post($this->baseUrl.$endpoint, $data);

            $body = $response->json();

            $success = ($body['Success'] ?? false) === true;
            $model = $body['Model'] ?? [];

            return [
                'success' => $success,
                'transaction_id' => $model['TransactionId'] ?? null,
                'message' => $body['Message'] ?? ($model['CardHolderMessage'] ?? 'Unknown'),
                'code' => $success ? 'ok' : ($model['ReasonCode'] ?? (string) ($body['Message'] ?? 'error')),
                'data' => $model,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'transaction_id' => null,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }
}
