<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

final class PaymentListRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['sometimes', 'string'],
            'filter.currency' => ['sometimes', 'string', 'size:3'],
            'filter.connector' => ['sometimes', 'string'],
            'filter.capture_method' => ['sometimes', 'string', 'in:automatic,manual'],
            'filter.amount_min' => ['sometimes', 'integer', 'min:0'],
            'filter.amount_max' => ['sometimes', 'integer', 'min:0'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date', 'after_or_equal:filter.from'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'string', 'in:created_at,-created_at,amount,-amount,status,-status'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page.number' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        return $this->validated('filter') ?? [];
    }

    public function sortParam(): ?string
    {
        return $this->validated('sort');
    }

    public function perPage(): int
    {
        return (int) ($this->input('page.size', 20));
    }
}
