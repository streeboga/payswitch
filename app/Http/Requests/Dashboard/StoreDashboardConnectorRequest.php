<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Http\Requests\Concerns\RequiresConnectorCredentials;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDashboardConnectorRequest extends FormRequest
{
    use RequiresConnectorCredentials;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>|string> */
    public function rules(): array
    {
        return [
            'connector_name' => ['required', 'string', Rule::in(config('payswitch.connectable'))],
            'connector_type' => 'required|string',
            'connector_account_details' => 'required|array',
            'profile_id' => 'sometimes|string',
            'payment_methods_enabled' => 'sometimes|array',
            'test_mode' => 'sometimes|boolean',
        ];
    }
}
