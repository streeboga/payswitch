<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Repositories\Contracts\DisputeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class DisputeRepository implements DisputeRepositoryInterface
{
    public function paginateForMerchant(
        int|string $merchantAccountId,
        ?string $status = null,
        ?string $type = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = Dispute::where('merchant_account_id', $merchantAccountId);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->orderByDesc('created_at')->paginate(min($perPage, 100));
    }

    public function findByKeyOrFail(string $disputeKey, int|string $merchantAccountId): Dispute
    {
        return Dispute::where('merchant_account_id', $merchantAccountId)
            ->where('key', $disputeKey)
            ->firstOrFail();
    }

    public function createEvidence(array $attributes): DisputeEvidence
    {
        return DisputeEvidence::create($attributes);
    }
}
