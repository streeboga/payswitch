<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class CustomerResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'customers';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_country_code' => $this->phone_country_code,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'default_payment_method_id' => $this->default_payment_method_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [
            'self' => "/api/v1/customers/{$this->key}",
        ];
    }
}
