<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Payment;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use Illuminate\Foundation\Http\FormRequest;

final class ConfirmPaymentRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string'],
            'payment_method_data' => ['sometimes', 'array'],
            'payment_method_data.*' => ['sometimes'],
            'connector' => ['sometimes', 'string'],
            'payment_method_id' => ['sometimes', 'string'],
        ];
    }

    public function toDto(): ConfirmPaymentData
    {
        return ConfirmPaymentData::from($this->validated());
    }
}
