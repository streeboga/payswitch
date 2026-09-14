<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Streeboga\PaymentData\Models\BusinessProfile;

/**
 * @mixin BusinessProfile
 */
final class BusinessProfileResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'profiles';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'merchant_id' => $this->merchantAccount->key,
            'webhook_url' => $this->webhook_url,
            // Ключ подписи вебхуков — admin API и admin мерчанта; остальным null.
            'payment_response_hash_key' => $request->attributes->get('api_key_type') === 'admin'
                || Gate::allows('business-profile.update', [$this->merchant_account_id])
                ? $this->payment_response_hash_key
                : null,
            'connectors_count' => $this->connector_accounts_count ?? 0,
            'routing_rules_count' => $this->routing_rules_count ?? 0,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/profiles/{$this->key}",
        ];
    }
}
