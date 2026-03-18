<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDashboardRoutingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(RoutingRuleType::class)],
            'name' => 'required|string|max:255',
            'rules' => 'required|array',
            'active' => 'sometimes|boolean',
            'priority' => 'sometimes|integer|min:0',
        ];
    }
}
