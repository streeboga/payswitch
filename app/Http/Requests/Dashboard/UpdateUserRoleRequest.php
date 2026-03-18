<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }
}
