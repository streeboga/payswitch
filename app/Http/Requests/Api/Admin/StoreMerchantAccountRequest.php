<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\CreateMerchantAccountData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreMerchantAccountRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function toDto(): CreateMerchantAccountData
    {
        return CreateMerchantAccountData::from($this->validated());
    }
}
