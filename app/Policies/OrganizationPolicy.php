<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roles()->exists();
    }

    public function view(User $user, int|string $organizationId): bool
    {
        return $user->roleForOrganization($organizationId) !== null;
    }

    public function create(User $user): bool
    {
        return $user->roles()->where('role', UserRole::Admin)->exists();
    }

    public function update(User $user, int|string $organizationId): bool
    {
        return $user->roleForOrganization($organizationId) === UserRole::Admin;
    }

    public function delete(User $user, int|string $organizationId): bool
    {
        return $user->roleForOrganization($organizationId) === UserRole::Admin;
    }
}
