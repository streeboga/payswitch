<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserSettingsPolicy
{
    public function view(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return true;
    }
}
