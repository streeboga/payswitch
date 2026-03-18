<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DisputeRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Dispute>
     */
    public function paginateForMerchant(
        int|string $merchantAccountId,
        ?string $status = null,
        ?string $type = null,
        int $perPage = 20,
    ): LengthAwarePaginator;

    public function findByKeyOrFail(string $disputeKey, int|string $merchantAccountId): Dispute;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createEvidence(array $attributes): DisputeEvidence;
}
