<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDashboardCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'phone' => 'sometimes|string|max:20',
            'phone_country_code' => 'sometimes|string|max:5',
            'description' => 'sometimes|string|max:1000',
            'metadata' => 'sometimes|array',
        ];
    }
}
