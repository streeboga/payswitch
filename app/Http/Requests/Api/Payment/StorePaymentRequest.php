<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Payment;

use App\DataTransferObjects\Payment\CreatePaymentData;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'data.attributes.currency' => ['required', 'string', 'size:3'],
            'data.attributes.capture_method' => ['sometimes', 'string', 'in:automatic,manual'],
            'data.attributes.authentication_type' => ['sometimes', 'string', 'in:three_ds,no_three_ds'],
            'data.attributes.customer_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'data.attributes.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'data.attributes.return_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'data.attributes.metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
            'data.attributes.session_expiry' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'data.attributes.payment_id' => ['sometimes', 'string', 'max:40'],
            'data.attributes.confirm' => ['sometimes', 'boolean'],
            'data.attributes.payment_method' => ['required_if:data.attributes.confirm,true', 'string'],
            'data.attributes.payment_method_data' => ['required_if:data.attributes.confirm,true', 'array'],
        ];
    }

    public function toDto(): CreatePaymentData
    {
        return CreatePaymentData::from($this->validated('data.attributes'));
    }
}
