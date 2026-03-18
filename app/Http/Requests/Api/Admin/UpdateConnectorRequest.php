<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\UpdateConnectorData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateConnectorRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'connector_name' => ['sometimes', 'string'],
            'disabled' => ['sometimes', 'boolean'],
            'test_mode' => ['sometimes', 'boolean'],
            'connector_account_details' => ['sometimes', 'array'],
            'payment_methods_enabled' => ['sometimes', 'array'],
            'profile_id' => ['sometimes', 'string'],
        ];
    }

    public function toDto(): UpdateConnectorData
    {
        return UpdateConnectorData::from($this->validated());
    }
}
