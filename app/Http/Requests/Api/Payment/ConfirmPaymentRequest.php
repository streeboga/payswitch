<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Payment;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmPaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.payment_method' => ['required', 'string'],
            'data.attributes.payment_method_data' => ['required', 'array'],
            'data.attributes.payment_method_data.*' => ['sometimes'],
            'data.attributes.connector' => ['sometimes', 'string'],
            'data.attributes.payment_method_id' => ['sometimes', 'string'],
        ];
    }

    public function toDto(): ConfirmPaymentData
    {
        return ConfirmPaymentData::from($this->validated('data.attributes'));
    }
}
