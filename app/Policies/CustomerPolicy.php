<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class CustomerPolicy extends MerchantPolicy
{
    public function viewAny(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }

    public function view(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }

    public function create(User $user, int|string $merchantId): bool
    {
        return $this->canWrite($user, $merchantId);
    }

    public function update(User $user, int|string $merchantId): bool
    {
        return $this->canWrite($user, $merchantId);
    }

    public function delete(User $user, int|string $merchantId): bool
    {
        return $this->isAdmin($user, $merchantId);
    }
}
