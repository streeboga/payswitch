<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\DataTransferObjects\Admin\CreateApiKeyData;
use App\Enums\ApiKeyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDashboardApiKeyRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::enum(ApiKeyType::class)],
        ];
    }

    public function toDto(): CreateApiKeyData
    {
        return CreateApiKeyData::from($this->validated());
    }
}
