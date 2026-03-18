<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function markRead(User $user): bool
    {
        return true;
    }

    public function delete(User $user): bool
    {
        return true;
    }
}
