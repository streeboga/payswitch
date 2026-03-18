<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserPreference;
use App\Repositories\Contracts\UserPreferenceRepositoryInterface;

final readonly class UserSettingsService
{
    public function __construct(
        private UserPreferenceRepositoryInterface $preferences,
    ) {}

    /**
     * Get or create default preferences for a user.
     */
    public function getOrCreatePreferences(int $userId): UserPreference
    {
        return $this->preferences->firstOrCreate($userId, [
            'timezone' => 'UTC',
            'date_format' => 'YYYY-MM-DD',
            'number_format' => 'en-US',
            'base_currency' => 'USD',
            'theme' => 'auto',
            'data_density' => 'comfortable',
            'notification_email' => true,
            'notification_inapp' => true,
        ]);
    }

    /**
     * Update or create preferences for a user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updatePreferences(int $userId, array $attributes): UserPreference
    {
        return $this->preferences->updateOrCreate($userId, $attributes);
    }
}
