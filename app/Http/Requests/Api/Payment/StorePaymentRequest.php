<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Payment;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\DataTransferObjects\Payment\CreatePaymentData;
use Illuminate\Foundation\Http\FormRequest;

final class StorePaymentRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'currency' => ['required', 'string', 'size:3'],
            'capture_method' => ['sometimes', 'string', 'in:automatic,manual'],
            'authentication_type' => ['sometimes', 'string', 'in:three_ds,no_three_ds'],
            'customer_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'return_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
            'session_expiry' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'payment_id' => ['sometimes', 'string', 'max:40'],
            'confirm' => ['sometimes', 'boolean'],
            'payment_method' => ['required_if:confirm,true', 'string'],
            'payment_method_data' => ['required_if:confirm,true', 'array'],
            'payment_method_data.*' => ['sometimes'],
            'connector' => ['sometimes', 'string'],
        ];
    }

    public function toDto(): CreatePaymentData
    {
        return CreatePaymentData::from($this->validated());
    }

    public function toConfirmDto(): ConfirmPaymentData
    {
        return ConfirmPaymentData::from($this->validated());
    }
}
