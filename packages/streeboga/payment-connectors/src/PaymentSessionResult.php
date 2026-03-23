<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors;

use Carbon\CarbonInterface;
use Streeboga\PaymentData\Enums\SessionResultType;

final readonly class PaymentSessionResult
{
    /**
     * @param  array<string, mixed>|null  $params
     */
    private function __construct(
        public SessionResultType $type,
        private ?string $url = null,
        private ?string $method = null,
        private ?array $params = null,
        private ?string $transactionId = null,
        private ?string $provider = null,
        private ?string $scriptUrl = null,
        private ?string $qrData = null,
        private ?string $format = null,
        private ?string $paymentId = null,
        private ?CarbonInterface $expiresAt = null,
    ) {}

    public static function serverRedirect(string $url, string $method = 'GET', ?string $transactionId = null): self
    {
        return new self(
            type: SessionResultType::ServerRedirect,
            url: $url,
            method: $method,
            transactionId: $transactionId,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function formRedirect(string $url, array $params, string $method = 'POST'): self
    {
        return new self(
            type: SessionResultType::FormRedirect,
            url: $url,
            method: $method,
            params: $params,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function embeddedWidget(string $provider, string $scriptUrl, array $params, ?string $transactionId = null): self
    {
        return new self(
            type: SessionResultType::EmbeddedWidget,
            params: $params,
            transactionId: $transactionId,
            provider: $provider,
            scriptUrl: $scriptUrl,
        );
    }

    public static function qrInline(string $qrData, string $format, string $paymentId, ?CarbonInterface $expiresAt = null, ?string $transactionId = null): self
    {
        return new self(
            type: SessionResultType::QrInline,
            transactionId: $transactionId,
            qrData: $qrData,
            format: $format,
            paymentId: $paymentId,
            expiresAt: $expiresAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            SessionResultType::ServerRedirect => [
                'type' => $this->type->value,
                'url' => $this->url,
                'method' => $this->method,
                'transaction_id' => $this->transactionId,
            ],
            SessionResultType::FormRedirect => [
                'type' => $this->type->value,
                'url' => $this->url,
                'params' => $this->params,
                'method' => $this->method,
            ],
            SessionResultType::EmbeddedWidget => [
                'type' => $this->type->value,
                'provider' => $this->provider,
                'script_url' => $this->scriptUrl,
                'params' => $this->params,
                'transaction_id' => $this->transactionId,
            ],
            SessionResultType::QrInline => [
                'type' => $this->type->value,
                'qr_data' => $this->qrData,
                'format' => $this->format,
                'payment_id' => $this->paymentId,
                'expires_at' => $this->expiresAt?->toIso8601ZuluString(),
                'transaction_id' => $this->transactionId,
            ],
        };
    }
}
