<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PaymentMethod;

use App\DataTransferObjects\PaymentMethod\CreatePaymentMethodData;
use Illuminate\Foundation\Http\FormRequest;

final class StorePaymentMethodRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:card,bank_account'],
            'connector_name' => ['required', 'string'],
            'card_number' => ['sometimes', 'string', 'max:19'],
            'card_last4' => ['sometimes', 'string', 'size:4'],
            'card_brand' => ['sometimes', 'string'],
            'card_exp_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'card_exp_year' => ['sometimes', 'integer', 'min:2024'],
            'card_holder_name' => ['sometimes', 'string', 'max:255'],
            'connector_token' => ['sometimes', 'string'],
            'is_default' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
        ];
    }

    /** @return array<string, mixed> */
    public function validatedAttributes(): array
    {
        return $this->validated();
    }

    public function toDto(): CreatePaymentMethodData
    {
        return CreatePaymentMethodData::from($this->validated());
    }
}
