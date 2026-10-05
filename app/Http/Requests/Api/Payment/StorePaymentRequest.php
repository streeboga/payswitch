<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Payment;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\DataTransferObjects\Payment\CreatePaymentData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StorePaymentRequest extends FormRequest
{
    /**
     * Ключ идемпотентности приходит заголовком; поле тела с тем же именем не принимается.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

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
            'project_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'operation_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'order_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'session_expiry' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'payment_id' => ['sometimes', 'string', 'max:40'],
            'confirm' => ['sometimes', 'boolean'],
            'payment_method' => ['required_if:confirm,true', 'string'],
            'payment_method_data' => ['required_if:confirm,true', 'array'],
            'payment_method_data.*' => ['sometimes'],
            'connector' => ['sometimes', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            // Кассовый чек (54-ФЗ), суммы в минимальных единицах. Словарь общий с мерчантом;
            // в коды кассы его переводит коннектор.
            'receipt' => ['sometimes', 'nullable', 'array'],
            'receipt.taxation_system' => ['required_with:receipt', 'string', 'in:osn,usn_income,usn_income_outcome,esn,patent'],
            'receipt.email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'receipt.items' => ['required_with:receipt', 'array', 'min:1', 'max:100'],
            'receipt.items.*.label' => ['required', 'string', 'max:128'],
            'receipt.items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'receipt.items.*.price' => ['required', 'integer', 'min:0'],
            'receipt.items.*.amount' => ['required', 'integer', 'min:0'],
            'receipt.items.*.vat' => ['required', 'in:none,0,5,7,10,20,22'],
            'receipt.items.*.payment_method' => ['required', 'string', 'in:full_prepayment,prepayment,advance,full_payment'],
            'receipt.items.*.payment_object' => ['required', 'string', 'in:commodity,service,payment,another'],
        ];
    }

    /**
     * Сумма чека обязана совпасть с суммой платежа: иначе касса пробьёт не то, что списано.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $items = $this->input('receipt.items');
            if ($validator->errors()->isEmpty() && is_array($items)
                && array_sum(array_column($items, 'amount')) !== (int) $this->input('amount')) {
                $validator->errors()->add('receipt.items', 'The receipt items amount must equal the payment amount.');
            }
        }];
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
