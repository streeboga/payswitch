<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\Customer;

/**
 * @mixin Customer
 */
final class CustomerResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'customers';
    }

    /** @return array<string, mixed> */
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

    /**
     * Build a JSON:API response with included relationships.
     *
     * @param  array<string>  $includes  Allowed include names (e.g. ['payment_methods', 'payments'])
     */
    public function toResponseWithIncludes(Request $request, array $includes = []): JsonResponse
    {
        $allowedIncludes = ['payment_methods' => 'paymentMethods', 'payments' => 'payments'];
        $relationsToLoad = [];

        foreach ($includes as $inc) {
            if (isset($allowedIncludes[$inc])) {
                $relationsToLoad[] = $allowedIncludes[$inc];
            }
        }

        if (! empty($relationsToLoad)) {
            $this->resource->load($relationsToLoad);
        }

        $data = $this->toArray($request);

        $included = [];
        if (in_array('payment_methods', $includes) && $this->resource->relationLoaded('paymentMethods')) {
            foreach ($this->resource->paymentMethods as $pm) {
                $included[] = (new PaymentMethodResource($pm))->toArray($request);
            }
        }
        if (in_array('payments', $includes) && $this->resource->relationLoaded('payments')) {
            foreach ($this->resource->payments as $payment) {
                $included[] = (new PaymentIntentResource($payment))->toArray($request);
            }
        }

        $response = ['data' => $data];
        if (! empty($included)) {
            $response['included'] = $included;
        }

        return response()->json($response, 200, ['Content-Type' => 'application/vnd.api+json']);
    }
}
