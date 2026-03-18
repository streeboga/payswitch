<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTestPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|integer|min:1',
            'currency' => 'sometimes|string|size:3',
            'capture_method' => 'sometimes|string|in:automatic,manual',
            'payment_method' => 'sometimes|string',
            'card_number' => 'sometimes|string',
            'payment_method_data' => 'sometimes|array',
        ];
    }
}
