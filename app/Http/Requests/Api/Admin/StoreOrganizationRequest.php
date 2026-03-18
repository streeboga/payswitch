<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\CreateOrganizationData;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.name' => ['required', 'string', 'max:255'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes');
    }

    public function toDto(): CreateOrganizationData
    {
        return CreateOrganizationData::from($this->validated('data.attributes'));
    }
}
