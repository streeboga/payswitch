<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\CreateApiKeyData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreApiKeyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.name' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function toDto(): CreateApiKeyData
    {
        return CreateApiKeyData::from($this->validated('data.attributes') ?? []);
    }
}
