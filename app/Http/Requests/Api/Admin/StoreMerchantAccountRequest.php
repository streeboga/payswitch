<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.organization_id' => ['required', 'string'],
            'data.attributes.name' => ['required', 'string', 'max:255'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes');
    }
}
