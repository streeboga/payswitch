<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class DisputePolicy extends MerchantPolicy
{
    public function viewAny(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }

    public function view(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }

    public function submitEvidence(User $user, int|string $merchantId): bool
    {
        return $this->canWrite($user, $merchantId);
    }
}
