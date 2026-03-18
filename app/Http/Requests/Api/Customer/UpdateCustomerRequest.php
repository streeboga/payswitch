<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Customer;

use App\DataTransferObjects\Customer\UpdateCustomerData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.name' => ['sometimes', 'string', 'max:255'],
            'data.attributes.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'data.attributes.phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'data.attributes.phone_country_code' => ['sometimes', 'nullable', 'string', 'max:5'],
            'data.attributes.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'data.attributes.metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
        ];
    }

    public function toDto(): UpdateCustomerData
    {
        return UpdateCustomerData::from($this->validated('data.attributes') ?? []);
    }
}
