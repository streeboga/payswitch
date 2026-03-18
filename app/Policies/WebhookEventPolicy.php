<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class WebhookEventPolicy extends MerchantPolicy
{
    public function viewAny(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }

    public function retry(User $user, int|string $merchantId): bool
    {
        return $this->canWrite($user, $merchantId);
    }
}
