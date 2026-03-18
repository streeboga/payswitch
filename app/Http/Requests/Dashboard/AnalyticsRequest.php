<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\DataTransferObjects\Analytics\PeriodFilter;
use Illuminate\Foundation\Http\FormRequest;

final class AnalyticsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.period' => ['sometimes', 'string', 'in:7d,30d,90d'],
            'filter.from' => ['sometimes', 'date', 'required_with:filter.to'],
            'filter.to' => ['sometimes', 'date', 'required_with:filter.from', 'after_or_equal:filter.from'],
        ];
    }

    public function merchantId(): int|string
    {
        return $this->attributes->get('merchant_id');
    }

    public function toPeriodFilter(): PeriodFilter
    {
        return PeriodFilter::fromRequest($this->validated('filter') ?? []);
    }
}
