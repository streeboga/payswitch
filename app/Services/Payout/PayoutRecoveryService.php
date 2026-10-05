<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\Models\Payout;
use App\Repositories\Eloquent\PayoutRepository;
use Illuminate\Support\Collection;

final readonly class PayoutRecoveryService
{
    public function __construct(private PayoutRepository $repository, private PayoutExecutionService $execution) {}

    /** @return Collection<int, Payout> Merchant+project-scoped pending queue, including claims left by crash. */
    public function pending(int $merchantId, string $projectId, int $limit = 100): Collection
    {
        return $this->repository->pending($merchantId, $projectId, max(1, min($limit, 1000)));
    }

    public function sync(string $key, int $merchantId, string $projectId): Payout
    {
        return $this->execution->sync($key, $merchantId, $projectId);
    }
}
