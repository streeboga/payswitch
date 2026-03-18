<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class MerchantAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roles()->exists();
    }

    public function view(User $user, int|string $merchantId): bool
    {
        return $user->roleForMerchant($merchantId) !== null;
    }

    public function create(User $user, int|string $organizationId): bool
    {
        return $user->roleForOrganization($organizationId) === UserRole::Admin;
    }

    public function update(User $user, int|string $merchantId): bool
    {
        $role = $user->roleForMerchant($merchantId);

        return $role !== null && $role->getWeight() >= UserRole::Operator->getWeight();
    }

    public function delete(User $user, int|string $merchantId): bool
    {
        return $user->roleForMerchant($merchantId) === UserRole::Admin;
    }
}
