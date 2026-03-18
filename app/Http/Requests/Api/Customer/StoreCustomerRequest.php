<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Customer;

use App\DataTransferObjects\Customer\CreateCustomerData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCustomerRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'nullable', 'string', 'min:1', 'max:64'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'phone_country_code' => ['sometimes', 'nullable', 'string', 'max:5'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
        ];
    }

    public function customId(): ?string
    {
        return $this->validated('id');
    }

    public function toDto(): CreateCustomerData
    {
        return CreateCustomerData::from($this->validated());
    }
}
