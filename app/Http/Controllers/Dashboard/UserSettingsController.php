<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateUserPreferenceRequest;
use App\Http\Resources\UserPreferenceResource;
use App\Services\UserSettingsService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard User Settings', description: 'User preferences and settings', weight: 26)]
final class UserSettingsController extends Controller
{
    public function __construct(
        private readonly UserSettingsService $settingsService,
    ) {}

    /**
     * Get user preferences
     *
     * Retrieve current user's preferences.
     */
    #[Response(200, description: 'User preferences')]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user() ?? abort(401);

        $prefs = $this->settingsService->getOrCreatePreferences($user->id);

        return (new UserPreferenceResource($prefs))->toResponse($request);
    }

    /**
     * Update user preferences
     *
     * Update one or more user preference fields.
     */
    #[Response(200, description: 'Preferences updated')]
    #[Response(422, description: 'Validation error')]
    public function update(UpdateUserPreferenceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user() ?? abort(401);

        $prefs = $this->settingsService->updatePreferences(
            $user->id,
            $validated,
        );

        return (new UserPreferenceResource($prefs))->toResponse($request);
    }
}
