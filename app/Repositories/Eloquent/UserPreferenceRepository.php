<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\UserPreference;
use App\Repositories\Contracts\UserPreferenceRepositoryInterface;

final readonly class UserPreferenceRepository implements UserPreferenceRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $defaults
     */
    public function firstOrCreate(int $userId, array $defaults): UserPreference
    {
        return UserPreference::firstOrCreate(
            ['user_id' => $userId],
            $defaults,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrCreate(int $userId, array $attributes): UserPreference
    {
        return UserPreference::updateOrCreate(
            ['user_id' => $userId],
            $attributes,
        );
    }
}
