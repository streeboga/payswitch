<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

/**
 * @mixin MerchantConnectorAccount
 */
final class ConnectorResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'connectors';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'connector_name' => $this->connector_name,
            'connector_type' => $this->connector_type,
            'connector_account_details' => $this->maskedCredentials(),
            'payment_methods_enabled' => $this->payment_methods_enabled,
            'test_mode' => $this->test_mode,
            'disabled' => $this->disabled,
            'webhook_url' => $this->buildWebhookUrl(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/dashboard/connectors/{$this->key}",
        ];
    }

    /** @return array<string, string> */
    private function maskedCredentials(): array
    {
        $details = $this->connector_account_details ?? [];
        if (! is_array($details)) {
            return [];
        }
        $masked = [];
        foreach ($details as $key => $value) {
            if (! is_string($value) || $value === '') {
                $masked[$key] = '';

                continue;
            }
            $masked[$key] = mb_strlen($value) > 8
                ? mb_substr($value, 0, 4).str_repeat('*', mb_strlen($value) - 4)
                : str_repeat('*', mb_strlen($value));
        }

        return $masked;
    }

    private function buildWebhookUrl(): string
    {
        $merchantKey = $this->merchantAccount?->key ?? '';
        $baseUrl = rtrim(config('app.url'), '/');

        return "{$baseUrl}/api/v1/webhooks/{$merchantKey}/{$this->key}";
    }
}
