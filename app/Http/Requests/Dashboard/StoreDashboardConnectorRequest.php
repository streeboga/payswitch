<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDashboardConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'connector_name' => 'required|string',
            'connector_type' => 'required|string',
            'connector_account_details' => 'required|array',
            'profile_id' => 'sometimes|string',
            'payment_methods_enabled' => 'sometimes|array',
            'test_mode' => 'sometimes|boolean',
        ];
    }
}
