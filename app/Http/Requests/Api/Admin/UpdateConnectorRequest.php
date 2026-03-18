<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\UpdateConnectorData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateConnectorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.connector_name' => ['sometimes', 'string'],
            'data.attributes.disabled' => ['sometimes', 'boolean'],
            'data.attributes.test_mode' => ['sometimes', 'boolean'],
            'data.attributes.connector_account_details' => ['sometimes', 'array'],
            'data.attributes.payment_methods_enabled' => ['sometimes', 'array'],
            'data.attributes.profile_id' => ['sometimes', 'string'],
        ];
    }

    public function toDto(): UpdateConnectorData
    {
        return UpdateConnectorData::from($this->validated('data.attributes') ?? []);
    }
}
