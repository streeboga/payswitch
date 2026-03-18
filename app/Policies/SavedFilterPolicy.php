<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class SavedFilterPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user): bool
    {
        return true;
    }
}
