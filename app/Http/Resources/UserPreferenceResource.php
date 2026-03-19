<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserPreference;
use Illuminate\Http\Request;

/**
 * @mixin UserPreference
 */
final class UserPreferenceResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return (string) $this->id;
    }

    public function toType(Request $request): string
    {
        return 'user-preferences';
    }

    /** @return array<string, mixed> */
    public function toAttributes(Request $request): array
    {
        return [
            'timezone' => $this->timezone,
            'date_format' => $this->date_format,
            'number_format' => $this->number_format,
            'base_currency' => $this->base_currency,
            'theme' => $this->theme,
            'data_density' => $this->data_density,
            'notification_email' => $this->notification_email,
            'notification_inapp' => $this->notification_inapp,
        ];
    }

    public function toLinks(Request $request): array
    {
        return [];
    }
}
