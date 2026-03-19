<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\PaymentMethod;

/**
 * @mixin PaymentMethod
 */
final class PaymentMethodResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'payment-methods';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'type' => $this->type,
            'card_last4' => $this->card_last4,
            'card_brand' => $this->card_brand,
            'card_exp_month' => $this->card_exp_month,
            'card_exp_year' => $this->card_exp_year,
            'card_holder_name' => $this->card_holder_name,
            'connector_name' => $this->connector_name,
            'is_default' => $this->is_default,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/payment-methods/{$this->key}",
        ];
    }
}
