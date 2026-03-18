<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class AnalyticsPolicy extends MerchantPolicy
{
    public function viewAny(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }
}
