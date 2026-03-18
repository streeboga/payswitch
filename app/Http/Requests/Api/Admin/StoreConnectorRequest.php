<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\CreateConnectorData;
use Illuminate\Foundation\Http\FormRequest;

class StoreConnectorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.connector_name' => ['required', 'string'],
            'data.attributes.connector_type' => ['required', 'string'],
            'data.attributes.connector_account_details' => ['required', 'array'],
            'data.attributes.profile_id' => ['sometimes', 'string'],
            'data.attributes.payment_methods_enabled' => ['sometimes', 'array'],
            'data.attributes.test_mode' => ['sometimes', 'boolean'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes');
    }

    public function toDto(): CreateConnectorData
    {
        return CreateConnectorData::from($this->validated('data.attributes'));
    }
}
