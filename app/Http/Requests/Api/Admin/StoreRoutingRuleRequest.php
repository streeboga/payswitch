<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\CreateRoutingRuleData;
use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRoutingRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(RoutingRuleType::class)],
            'name' => ['required', 'string', 'max:255'],
            'rules' => ['required', 'array'],
            'active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'business_profile_id' => ['sometimes', 'string'],
        ];
    }

    public function toDto(): CreateRoutingRuleData
    {
        return CreateRoutingRuleData::from($this->validated());
    }
}
