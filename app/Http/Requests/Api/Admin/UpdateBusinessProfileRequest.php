<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBusinessProfileRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }
}
