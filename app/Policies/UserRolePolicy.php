<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserRolePolicy extends MerchantPolicy
{
    public function viewAny(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }

    public function assign(User $user, int|string $merchantId): bool
    {
        return $this->isAdmin($user, $merchantId);
    }

    public function update(User $user, int|string $merchantId): bool
    {
        return $this->isAdmin($user, $merchantId);
    }

    public function delete(User $user, int|string $merchantId): bool
    {
        return $this->isAdmin($user, $merchantId);
    }
}
