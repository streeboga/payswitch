<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class ConnectorHealthPolicy extends MerchantPolicy
{
    public function view(User $user, int|string $merchantId): bool
    {
        return $this->canRead($user, $merchantId);
    }
}
