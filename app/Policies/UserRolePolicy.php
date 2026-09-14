<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class UserRolePolicy extends MerchantPolicy
{
    public function viewAny(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }

    /**
     * Назначать, менять и снимать роли может только admin той организации,
     * к которой относится роль, — не текущего мерчанта из заголовка.
     */
    public function assign(User $user, int|string $organizationId): bool
    {
        return $user->roleForOrganization($organizationId) === UserRole::Admin;
    }

    public function update(User $user, int|string $organizationId): bool
    {
        return $this->assign($user, $organizationId);
    }

    public function delete(User $user, int|string $organizationId): bool
    {
        return $this->assign($user, $organizationId);
    }
}
