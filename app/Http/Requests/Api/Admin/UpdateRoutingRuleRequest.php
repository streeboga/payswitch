<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRoutingRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.type' => ['sometimes', 'string', Rule::enum(RoutingRuleType::class)],
            'data.attributes.name' => ['sometimes', 'string', 'max:255'],
            'data.attributes.rules' => ['sometimes', 'array'],
            'data.attributes.active' => ['sometimes', 'boolean'],
            'data.attributes.priority' => ['sometimes', 'integer', 'min:0'],
            'data.attributes.business_profile_id' => ['sometimes', 'string'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes') ?? [];
    }
}
