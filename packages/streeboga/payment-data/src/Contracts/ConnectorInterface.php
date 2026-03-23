<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Contracts;

use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Enums\PaymentStatus;

interface ConnectorInterface
{
    /**
     * Return the static capabilities descriptor for this connector.
     */
    public static function capabilities(): ConnectorCapabilities;

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

    /**
     * Void (cancel) an authorized payment that has not yet been captured.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function void(array $params): array;

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

    /**
     * Query the PSP for the current status of a payment.
     *
     * @param  array<string, mixed>  $params  ['transaction_id' => 'psp_txn_id']
     * @return array<string, mixed> ['success' => bool, 'transaction_id' => ..., 'code' => ..., 'data' => [...]]
     */
    public function getPaymentStatus(array $params): array;

    /**
     * Map a raw PSP payment status string to an internal PaymentStatus enum.
     *
     * Used by the sync flow to translate the PSP-specific status into
     * the system's canonical status representation.
     */
    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus;

    /**
     * Create a payment session on the PSP and return a redirect URL.
     *
     * Used for the redirect-based confirm flow where the merchant sends
     * only `payment_method: 'card'` without raw card data.
     *
     * @param  array<string, mixed>  $params  Keys: amount, currency, payment_id, return_url, description, payment_method
     * @return array<string, mixed> Keys: success, redirect_url, session_id, code, transaction_id, data
     */
    public function createPaymentSession(array $params): PaymentSessionResult|array;

    /**
     * Test the connection to the PSP by performing a lightweight health check.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array;
}
