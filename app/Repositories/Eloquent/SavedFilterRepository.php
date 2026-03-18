<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\SavedFilter;
use App\Repositories\Contracts\SavedFilterRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

final readonly class SavedFilterRepository implements SavedFilterRepositoryInterface
{
    public function allForUser(int|string $userId): Collection
    {
        return SavedFilter::where('user_id', $userId)
            ->orderBy('sort_order')
            ->get();
    }

    public function create(array $attributes): SavedFilter
    {
        return SavedFilter::create($attributes);
    }

    public function findForUserOrFail(string $filterId, int|string $userId): SavedFilter
    {
        return SavedFilter::where('user_id', $userId)
            ->findOrFail($filterId);
    }

    public function delete(SavedFilter $filter): void
    {
        $filter->delete();
    }
}
