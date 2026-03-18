<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSavedFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table_name' => 'required|string|in:payments,refunds,disputes,webhook-events,customers',
            'name' => 'required|string|max:100',
            'filters' => 'required|array',
        ];
    }
}
