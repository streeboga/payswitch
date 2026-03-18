<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\CreateBusinessProfileData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreBusinessProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'string'],
            'webhook_url' => ['sometimes', 'url', 'max:2048'],
        ];
    }

    public function toDto(): CreateBusinessProfileData
    {
        return CreateBusinessProfileData::from($this->validated());
    }
}
