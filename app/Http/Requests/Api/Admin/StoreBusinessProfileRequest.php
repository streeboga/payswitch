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
            'data.attributes.merchant_id' => ['required', 'string'],
            'data.attributes.webhook_url' => ['sometimes', 'url', 'max:2048'],
        ];
    }

    public function toDto(): CreateBusinessProfileData
    {
        return CreateBusinessProfileData::from($this->validated('data.attributes'));
    }
}
