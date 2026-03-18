<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\DataTransferObjects\Admin\CreateMerchantAccountData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDashboardMerchantRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'organization_id' => ['required', 'string'],
        ];
    }

    public function toDto(): CreateMerchantAccountData
    {
        return CreateMerchantAccountData::from($this->validated());
    }
}
