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
            // admin из панели ничего не давал: admin API — только ключ из env.
            'type' => ['sometimes', 'string', Rule::enum(ApiKeyType::class)->except(ApiKeyType::Admin)],
        ];
    }

    public function toDto(): CreateApiKeyData
    {
        return CreateApiKeyData::from($this->validated());
    }
}
