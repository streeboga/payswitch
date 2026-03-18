<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.merchant_id' => ['required', 'string'],
            'data.attributes.webhook_url' => ['sometimes', 'url', 'max:2048'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes');
    }
}
