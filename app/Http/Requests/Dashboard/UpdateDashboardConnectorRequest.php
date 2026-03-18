<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDashboardConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'connector_account_details' => 'sometimes|array',
            'payment_methods_enabled' => 'sometimes|array',
            'test_mode' => 'sometimes|boolean',
            'disabled' => 'sometimes|boolean',
        ];
    }
}
