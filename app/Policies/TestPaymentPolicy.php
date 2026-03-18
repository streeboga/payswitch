<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class TestPaymentPolicy extends MerchantPolicy
{
    public function create(User $user, int|string $merchantId): bool
    {
        return $this->canWrite($user, $merchantId);
    }
}
