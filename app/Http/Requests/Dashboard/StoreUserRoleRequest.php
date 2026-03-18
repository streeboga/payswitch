<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'organization_id' => 'required|exists:organizations,id',
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }
}
