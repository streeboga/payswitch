<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\PaymentIntent;

/**
 * @mixin PaymentIntent
 */
final class PublicPaymentIntentResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'payments';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'metadata' => $this->filterPublicMetadata($this->metadata),
        ];
    }

    /** @return array<string, mixed>|null */
    private function filterPublicMetadata(?array $metadata): ?array
    {
        if (! $metadata) {
            return null;
        }

        return array_intersect_key($metadata, array_flip([
            'redirect_url',
            'redirect_method',
            'widget_data',
            // v2 PaymentSessionResult fields
            'type',
            'url',
            'method',
            'params',
            'provider',
            'script_url',
            'transaction_id',
            'qr_data',
            'format',
            'payment_id',
            'expires_at',
        ]));
    }
}
