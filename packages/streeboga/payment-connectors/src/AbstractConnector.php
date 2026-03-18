<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

use Omnipay\Common\GatewayInterface;
use Omnipay\Omnipay;
use Streeboga\PaymentData\Contracts\ConnectorInterface;

abstract class AbstractConnector implements ConnectorInterface
{
    protected GatewayInterface $gateway;

    public function __construct(array $credentials)
    {
        $this->gateway = Omnipay::create($this->getGatewayName());
        $this->configureGateway($credentials);
    }

    abstract protected function getGatewayName(): string;

    abstract protected function configureGateway(array $credentials): void;

    abstract protected function mapPurchaseParams(array $params): array;

    abstract protected function mapAuthorizeParams(array $params): array;

    abstract protected function mapCaptureParams(array $params): array;

    abstract protected function mapRefundParams(array $params): array;

    public function purchase(array $params): array
    {
        try {
            $response = $this->gateway->purchase($this->mapPurchaseParams($params))->send();

            return [
                'success' => $response->isSuccessful(),
                'transaction_id' => $response->getTransactionReference(),
                'message' => $response->getMessage(),
                'code' => $response->getCode(),
                'data' => $response->getData(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_error'];
        }
    }

    public function authorize(array $params): array
    {
        try {
            $response = $this->gateway->authorize($this->mapAuthorizeParams($params))->send();

            return [
                'success' => $response->isSuccessful(),
                'transaction_id' => $response->getTransactionReference(),
                'message' => $response->getMessage(),
                'code' => $response->getCode(),
                'data' => $response->getData(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_error'];
        }
    }

    public function capture(array $params): array
    {
        try {
            $response = $this->gateway->capture($this->mapCaptureParams($params))->send();

            return [
                'success' => $response->isSuccessful(),
                'transaction_id' => $response->getTransactionReference(),
                'message' => $response->getMessage(),
                'code' => $response->getCode(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_error'];
        }
    }

    public function refund(array $params): array
    {
        try {
            $response = $this->gateway->refund($this->mapRefundParams($params))->send();

            return [
                'success' => $response->isSuccessful(),
                'transaction_id' => $response->getTransactionReference(),
                'message' => $response->getMessage(),
                'code' => $response->getCode(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'connector_error'];
        }
    }
}
