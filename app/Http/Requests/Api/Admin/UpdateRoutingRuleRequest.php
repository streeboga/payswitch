<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\DataTransferObjects\Admin\UpdateRoutingRuleData;
use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRoutingRuleRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::enum(RoutingRuleType::class)],
            'name' => ['sometimes', 'string', 'max:255'],
            'rules' => ['sometimes', 'array'],
            'active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'business_profile_id' => ['sometimes', 'string'],
        ];
    }

    public function toDto(): UpdateRoutingRuleData
    {
        return UpdateRoutingRuleData::from($this->validated());
    }
}
