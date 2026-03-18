<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\DataTransferObjects\Admin\CreateOrganizationData;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDashboardOrganizationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function toDto(): CreateOrganizationData
    {
        return CreateOrganizationData::from($this->validated());
    }
}
