<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Refund;

use App\DataTransferObjects\Refund\CreateRefundData;
use Illuminate\Foundation\Http\FormRequest;

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
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toDto(): CreateRefundData
    {
        return CreateRefundData::from($this->validated());
    }
}
