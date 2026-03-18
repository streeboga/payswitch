<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard User Settings', description: 'User preferences and settings', weight: 26)]
final class UserSettingsController extends Controller
{
    /**
     * Get user preferences
     *
     * Retrieve current user's preferences.
     */
    #[Response(200, description: 'User preferences')]
    public function show(Request $request): JsonResponse
    {
        $prefs = UserPreference::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'timezone' => 'UTC',
                'date_format' => 'YYYY-MM-DD',
                'number_format' => 'en-US',
                'base_currency' => 'USD',
                'theme' => 'auto',
                'data_density' => 'comfortable',
                'notification_email' => true,
                'notification_inapp' => true,
            ],
        );

        return response()->json([
            'data' => [
                'type' => 'user-preferences',
                'id' => (string) $prefs->id,
                'attributes' => [
                    'timezone' => $prefs->timezone,
                    'date_format' => $prefs->date_format,
                    'number_format' => $prefs->number_format,
                    'base_currency' => $prefs->base_currency,
                    'theme' => $prefs->theme,
                    'data_density' => $prefs->data_density,
                    'notification_email' => $prefs->notification_email,
                    'notification_inapp' => $prefs->notification_inapp,
                ],
            ],
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    /**
     * Update user preferences
     *
     * Update one or more user preference fields.
     */
    #[Response(200, description: 'Preferences updated')]
    #[Response(422, description: 'Validation error')]
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data.attributes.timezone' => 'sometimes|string|timezone:all',
            'data.attributes.date_format' => 'sometimes|string|max:20',
            'data.attributes.number_format' => 'sometimes|string|max:10',
            'data.attributes.base_currency' => 'sometimes|string|size:3',
            'data.attributes.theme' => 'sometimes|string|in:light,dark,auto',
            'data.attributes.data_density' => 'sometimes|string|in:compact,comfortable,spacious',
            'data.attributes.notification_email' => 'sometimes|boolean',
            'data.attributes.notification_inapp' => 'sometimes|boolean',
        ]);

        $prefs = UserPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated['data']['attributes'] ?? [],
        );

        return response()->json([
            'data' => [
                'type' => 'user-preferences',
                'id' => (string) $prefs->id,
                'attributes' => [
                    'timezone' => $prefs->timezone,
                    'date_format' => $prefs->date_format,
                    'number_format' => $prefs->number_format,
                    'base_currency' => $prefs->base_currency,
                    'theme' => $prefs->theme,
                    'data_density' => $prefs->data_density,
                    'notification_email' => $prefs->notification_email,
                    'notification_inapp' => $prefs->notification_inapp,
                ],
            ],
        ], 200, ['Content-Type' => 'application/vnd.api+json']);
    }
}
