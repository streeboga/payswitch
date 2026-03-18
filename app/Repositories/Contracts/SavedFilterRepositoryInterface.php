<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\SavedFilter;
use Illuminate\Database\Eloquent\Collection;

interface SavedFilterRepositoryInterface
{
    /**
     * @return Collection<int, SavedFilter>
     */
    public function allForUser(int|string $userId): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): SavedFilter;

    public function findForUserOrFail(string $filterId, int|string $userId): SavedFilter;

    public function delete(SavedFilter $filter): void;
}
