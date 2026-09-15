<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\CreateConnectorData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreConnectorRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'connector_name' => ['required', 'string', Rule::in(config('payswitch.connectable'))],
            'connector_type' => ['required', 'string'],
            'connector_account_details' => ['required', 'array'],
            'profile_id' => ['sometimes', 'string'],
            'payment_methods_enabled' => ['sometimes', 'array'],
            'test_mode' => ['sometimes', 'boolean'],
        ];
    }

    public function toDto(): CreateConnectorData
    {
        return CreateConnectorData::from($this->validated());
    }
}
