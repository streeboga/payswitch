<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Refund;

use App\DataTransferObjects\Refund\CreateRefundData;
use App\Http\Requests\Api\Payment\StorePaymentRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRefundRequest extends FormRequest
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
            'payment_id' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            ...StorePaymentRequest::receiptRules(),
        ];
    }

    /**
     * Состав чека возврата обязан сойтись с суммой возврата: касса пробьёт то, что в чеке.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $items = $this->input('receipt.items');
            if ($validator->errors()->isEmpty() && is_array($items)
                && array_sum(array_column($items, 'amount')) !== (int) $this->input('amount')) {
                $validator->errors()->add('receipt.items', 'The receipt items amount must equal the refund amount.');
            }
        }];
    }

    public function toDto(): CreateRefundData
    {
        return CreateRefundData::from($this->validated());
    }
}
