<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PaymentMethod;

use App\DataTransferObjects\PaymentMethod\CreatePaymentMethodData;
use Illuminate\Foundation\Http\FormRequest;

final class StorePaymentMethodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.type' => ['required', 'string', 'in:card,bank_account'],
            'data.attributes.connector_name' => ['required', 'string'],
            'data.attributes.card_number' => ['sometimes', 'string', 'max:19'],
            'data.attributes.card_last4' => ['sometimes', 'string', 'size:4'],
            'data.attributes.card_brand' => ['sometimes', 'string'],
            'data.attributes.card_exp_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'data.attributes.card_exp_year' => ['sometimes', 'integer', 'min:2024'],
            'data.attributes.card_holder_name' => ['sometimes', 'string', 'max:255'],
            'data.attributes.connector_token' => ['sometimes', 'string'],
            'data.attributes.is_default' => ['sometimes', 'boolean'],
            'data.attributes.metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes') ?? [];
    }

    public function toDto(): CreatePaymentMethodData
    {
        return CreatePaymentMethodData::from($this->validated('data.attributes') ?? []);
    }
}
