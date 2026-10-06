<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Креды подключения обязательны по коннектору: пустой секрет CloudPayments
 * превращает проверку подписи вебхука в HMAC с пустым ключом.
 */
trait RequiresConnectorCredentials
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $details = $this->input('connector_account_details');
            if ($this->input('connector_name') !== 'cloudpayments' || ! is_array($details)) {
                return;
            }

            $filled = fn (string $key): bool => is_string($details[$key] ?? null) && trim($details[$key]) !== '';

            if (! $filled('public_id')) {
                $v->errors()->add('connector_account_details.public_id', 'public_id is required for cloudpayments.');
            }
            if (! $filled('api_secret') && ! $filled('api_key')) {
                $v->errors()->add('connector_account_details.api_secret', 'api_secret is required for cloudpayments.');
            }
        });
    }
}
