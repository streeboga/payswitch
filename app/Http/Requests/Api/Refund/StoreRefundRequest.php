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
            'data.attributes.payment_id' => ['required', 'string'],
            'data.attributes.amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toDto(): CreateRefundData
    {
        return CreateRefundData::from($this->validated('data.attributes'));
    }
}
