<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

abstract class MerchantPolicy
{
    /**
     * Get the user's role for the given merchant.
     */
    protected function role(User $user, int|string $merchantId): ?UserRole
    {
        return $user->roleForMerchant($merchantId);
    }

    /**
     * Check if user has at least the given role level.
     */
    protected function hasMinimumRole(User $user, int|string $merchantId, UserRole $minimum): bool
    {
        $role = $this->role($user, $merchantId);

        return $role !== null && $role->getWeight() >= $minimum->getWeight();
    }

    /**
     * Check if user is admin for the merchant.
     */
    protected function isAdmin(User $user, int|string $merchantId): bool
    {
        return $this->role($user, $merchantId) === UserRole::Admin;
    }

    /**
     * Check if user can write (Admin or Operator).
     */
    protected function canWrite(User $user, int|string $merchantId): bool
    {
        return $this->hasMinimumRole($user, $merchantId, UserRole::Operator);
    }

    /**
     * Check if user can read (any role).
     */
    protected function canRead(User $user, int|string $merchantId): bool
    {
        return $this->role($user, $merchantId) !== null;
    }
}
