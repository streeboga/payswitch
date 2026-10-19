<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Payment;

use Illuminate\Foundation\Http\FormRequest;

final class CancelPaymentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
