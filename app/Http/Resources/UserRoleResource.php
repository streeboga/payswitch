<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class UserRoleResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return (string) $this->id;
    }

    public function toType(Request $request): string
    {
        return 'user-roles';
    }

    public function toAttributes(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'role' => $this->role->value,
            'two_factor_enabled' => (bool) $this->user?->two_factor_confirmed_at,
            'last_login_at' => null,
            'status' => 'active',
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks(Request $request): array
    {
        return [];
    }
}
