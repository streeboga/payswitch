<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;

abstract class HttpConnector implements ConnectorInterface
{
    protected string $baseUrl;

    /** @var array<string, mixed> */
    protected array $credentials;

    /** @param array<string, mixed> $credentials */
    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
    }

    // --- Subclass MUST implement ---

    /**
     * Configure authentication for outgoing requests.
     *
     * @return array{auth?: array{0: string, 1: string}, token?: string, headers?: array<string, string>}
     */
    abstract protected function configureRequest(): array;

    /**
     * Build the HTTP request for a purchase operation.
     *
     * @param  array<string, mixed>  $params
     * @return array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}
     */
    abstract protected function buildPurchaseRequest(array $params): array;

    /**
     * Parse the purchase response body into a standard connector response.
     *
     * @param  array<string, mixed>  $body
     * @return array{success: bool, transaction_id: ?string, message: ?string, code: ?string, data: array<string, mixed>}
     */
    abstract protected function parsePurchaseResponse(array $body): array;

    /**
     * Build the HTTP request for creating a payment session.
     *
     * @param  array<string, mixed>  $params
     * @return array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}
     */
    abstract protected function buildCreateSessionRequest(array $params): array;

    /**
     * Parse the session creation response into a PaymentSessionResult.
     *
     * @param  array<string, mixed>  $body
     */
    abstract protected function parseCreateSessionResponse(array $body): PaymentSessionResult;

    // --- Optional overrides (defaults delegate to purchase methods) ---

    /**
     * @param  array<string, mixed>  $params
     * @return array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}
     */
    protected function buildAuthorizeRequest(array $params): array
    {
        return $this->buildPurchaseRequest($params);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function parseAuthorizeResponse(array $body): array
    {
        return $this->parsePurchaseResponse($body);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}
     */
    protected function buildCaptureRequest(array $params): array
    {
        return ['endpoint' => '', 'body' => []];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function parseCaptureResponse(array $body): array
    {
        return $this->parsePurchaseResponse($body);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}
     */
    protected function buildRefundRequest(array $params): array
    {
        return ['endpoint' => '', 'body' => []];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function parseRefundResponse(array $body): array
    {
        return $this->parsePurchaseResponse($body);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}
     */
    protected function buildVoidRequest(array $params): array
    {
        return ['endpoint' => '', 'body' => []];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function parseVoidResponse(array $body): array
    {
        return $this->parsePurchaseResponse($body);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}
     */
    protected function buildGetStatusRequest(array $params): array
    {
        return ['endpoint' => '', 'body' => []];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function parseGetStatusResponse(array $body): array
    {
        return $this->parsePurchaseResponse($body);
    }

    // --- Public methods (ConnectorInterface) implemented via build/parse pattern ---

    /** {@inheritDoc} */
    public function purchase(array $params): array
    {
        return $this->executeOperation(
            fn () => $this->buildPurchaseRequest($params),
            fn (array $body) => $this->parsePurchaseResponse($body),
        );
    }

    /** {@inheritDoc} */
    public function authorize(array $params): array
    {
        return $this->executeOperation(
            fn () => $this->buildAuthorizeRequest($params),
            fn (array $body) => $this->parseAuthorizeResponse($body),
        );
    }

    /** {@inheritDoc} */
    public function capture(array $params): array
    {
        return $this->executeOperation(
            fn () => $this->buildCaptureRequest($params),
            fn (array $body) => $this->parseCaptureResponse($body),
        );
    }

    /** {@inheritDoc} */
    public function refund(array $params): array
    {
        return $this->executeOperation(
            fn () => $this->buildRefundRequest($params),
            fn (array $body) => $this->parseRefundResponse($body),
        );
    }

    /** {@inheritDoc} */
    public function void(array $params): array
    {
        return $this->executeOperation(
            fn () => $this->buildVoidRequest($params),
            fn (array $body) => $this->parseVoidResponse($body),
        );
    }

    /** {@inheritDoc} */
    public function getPaymentStatus(array $params): array
    {
        return $this->executeOperation(
            fn () => $this->buildGetStatusRequest($params),
            fn (array $body) => $this->parseGetStatusResponse($body),
        );
    }

    /**
     * Create a payment session on the PSP.
     *
     * Returns PaymentSessionResult on success, or an error array on failure.
     *
     * @param  array<string, mixed>  $params
     * @return PaymentSessionResult|array<string, mixed>
     */
    public function createPaymentSession(array $params): PaymentSessionResult|array
    {
        try {
            $request = $this->buildCreateSessionRequest($params);
            $body = $this->sendRequest($request);

            return $this->parseCreateSessionResponse($body);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }

    /**
     * Test the connection to the PSP by performing a lightweight health check.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        try {
            $pendingRequest = Http::acceptJson();
            $pendingRequest = $this->applyAuth($pendingRequest);

            $response = $pendingRequest->get($this->baseUrl);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Connection successful'];
            }

            return [
                'success' => false,
                'message' => 'HTTP '.$response->status().': '.$response->body(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // --- Amount helper ---

    /**
     * Format an amount (in minor units / kopecks) according to the connector's amount unit.
     *
     * - Rubles: divides by 100 → "50.00"
     * - Kopecks / MinorUnits: returns as-is → "5000"
     */
    public function formatAmount(int $amount): string
    {
        $amountUnit = static::capabilities()->amountUnit;

        return match ($amountUnit) {
            AmountUnit::Rubles => number_format($amount / 100, 2, '.', ''),
            AmountUnit::Kopecks, AmountUnit::MinorUnits => (string) $amount,
        };
    }

    // --- Internal HTTP ---

    /**
     * Execute an operation via the build/parse pattern with error handling.
     *
     * @param  callable(): array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}  $buildRequest
     * @param  callable(array<string, mixed>): array<string, mixed>  $parseResponse
     * @return array<string, mixed>
     */
    private function executeOperation(callable $buildRequest, callable $parseResponse): array
    {
        try {
            $request = $buildRequest();
            $body = $this->sendRequest($request);

            return $parseResponse($body);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }

    /**
     * Send an HTTP request to the PSP.
     *
     * @param  array{endpoint: string, body: array<string, mixed>, method?: string, headers?: array<string, string>}  $request
     * @return array<string, mixed>
     */
    private function sendRequest(array $request): array
    {
        $endpoint = $request['endpoint'] ?? '';
        $body = $request['body'] ?? [];
        $method = strtoupper($request['method'] ?? 'POST');
        $headers = $request['headers'] ?? [];

        if ($endpoint !== '') {
            $url = rtrim($this->baseUrl, '/').'/'.ltrim($endpoint, '/');
        } else {
            $url = $this->baseUrl;
        }

        $pendingRequest = Http::acceptJson();

        if ($headers !== []) {
            $pendingRequest = $pendingRequest->withHeaders($headers);
        }

        $pendingRequest = $this->applyAuth($pendingRequest);

        /** @var Response $response */
        $response = match ($method) {
            'GET' => $pendingRequest->get($url, $body),
            'PUT' => $pendingRequest->put($url, $body),
            'PATCH' => $pendingRequest->patch($url, $body),
            'DELETE' => $pendingRequest->delete($url, $body),
            default => $pendingRequest->post($url, $body),
        };

        return $response->json() ?? [];
    }

    /**
     * Apply authentication configured by configureRequest() to the pending HTTP request.
     */
    private function applyAuth(PendingRequest $pendingRequest): PendingRequest
    {
        $config = $this->configureRequest();

        if (isset($config['auth'])) {
            $pendingRequest = $pendingRequest->withBasicAuth($config['auth'][0], $config['auth'][1]);
        }

        if (isset($config['token'])) {
            $pendingRequest = $pendingRequest->withToken(
                str_replace('Bearer ', '', $config['token'])
            );
        }

        if (isset($config['headers'])) {
            $pendingRequest = $pendingRequest->withHeaders($config['headers']);
        }

        return $pendingRequest;
    }
}
