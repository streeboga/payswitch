<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\UserPreference;

interface UserPreferenceRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $defaults
     */
    public function firstOrCreate(int $userId, array $defaults): UserPreference;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOrCreate(int $userId, array $attributes): UserPreference;
}
