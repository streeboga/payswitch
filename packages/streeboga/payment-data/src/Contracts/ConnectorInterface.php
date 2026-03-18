<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Contracts;

use Streeboga\PaymentData\Enums\PaymentStatus;

interface ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function authorize(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function purchase(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function capture(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function refund(array $params): array;

    public function getName(): string;

    /**
     * Verify the webhook signature from the PSP.
     *
     * @param  array<string, string|null>  $headers  Request headers
     */
    public function verifyWebhookSignature(string $payload, array $headers): bool;

    /**
     * Map a PSP-specific webhook event type to an internal PaymentStatus.
     */
    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus;

    /**
     * Extract the internal payment ID from the PSP webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function extractPaymentIdFromWebhook(array $payload): ?string;
}
