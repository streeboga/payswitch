<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDashboardRoutingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(RoutingRuleType::class)],
            'name' => 'sometimes|string|max:255',
            'rules' => 'sometimes|array',
            'active' => 'sometimes|boolean',
            'priority' => 'sometimes|integer|min:0',
        ];
    }
}
