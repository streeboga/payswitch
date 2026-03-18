<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timezone' => 'sometimes|string|timezone:all',
            'date_format' => 'sometimes|string|max:20',
            'number_format' => 'sometimes|string|max:10',
            'base_currency' => 'sometimes|string|size:3',
            'theme' => 'sometimes|string|in:light,dark,auto',
            'data_density' => 'sometimes|string|in:compact,comfortable,spacious',
            'notification_email' => 'sometimes|boolean',
            'notification_inapp' => 'sometimes|boolean',
        ];
    }
}
