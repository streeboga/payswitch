<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\DataTransferObjects\Admin\UpdateMerchantAccountData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDashboardMerchantRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function toDto(): UpdateMerchantAccountData
    {
        return UpdateMerchantAccountData::from($this->validated());
    }
}
