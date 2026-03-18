<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Refund;

use App\DataTransferObjects\Refund\CreateRefundData;
use Illuminate\Foundation\Http\FormRequest;

class StoreRefundRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payment_id' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toDto(): CreateRefundData
    {
        return CreateRefundData::from($this->validated());
    }
}
