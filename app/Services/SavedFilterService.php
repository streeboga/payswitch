<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SavedFilter;
use App\Repositories\Contracts\SavedFilterRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

final readonly class SavedFilterService
{
    public function __construct(
        private SavedFilterRepositoryInterface $filters,
    ) {}

    /**
     * @return Collection<int, SavedFilter>
     */
    public function list(int|string $userId): Collection
    {
        return $this->filters->allForUser($userId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(int|string $userId, array $attributes): SavedFilter
    {
        return $this->filters->create([
            'user_id' => $userId,
            ...$attributes,
        ]);
    }

    public function delete(string $filterId, int|string $userId): void
    {
        $filter = $this->filters->findForUserOrFail($filterId, $userId);
        $this->filters->delete($filter);
    }
}
