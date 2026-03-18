<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Payment;

use App\DataTransferObjects\Payment\CapturePaymentData;
use Illuminate\Foundation\Http\FormRequest;

class CapturePaymentRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'amount_to_capture' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toDto(): CapturePaymentData
    {
        return CapturePaymentData::from($this->validated());
    }
}
