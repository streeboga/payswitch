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
            'period' => ['sometimes', 'string', 'in:7d,30d,90d'],
            'from' => ['sometimes', 'date', 'required_with:to'],
            'to' => ['sometimes', 'date', 'required_with:from', 'after_or_equal:from'],
        ];
    }

    public function merchantId(): int|string
    {
        return $this->attributes->get('merchant_id');
    }

    public function toPeriodFilter(): PeriodFilter
    {
        $filter = $this->validated('filter') ?? [];

        if (empty($filter['period']) && $this->validated('period')) {
            $filter['period'] = $this->validated('period');
        }
        if (empty($filter['from']) && $this->validated('from')) {
            $filter['from'] = $this->validated('from');
        }
        if (empty($filter['to']) && $this->validated('to')) {
            $filter['to'] = $this->validated('to');
        }

        return PeriodFilter::fromRequest($filter);
    }
}
