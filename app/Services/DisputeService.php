<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Repositories\Contracts\DisputeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class DisputeService
{
    public function __construct(
        private DisputeRepositoryInterface $disputes,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Dispute>
     */
    public function list(
        int|string $merchantAccountId,
        ?string $status = null,
        ?string $type = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return $this->disputes->paginateForMerchant($merchantAccountId, $status, $type, $perPage);
    }

    public function find(string $disputeKey, int|string $merchantAccountId): Dispute
    {
        return $this->disputes->findByKeyOrFail($disputeKey, $merchantAccountId);
    }

    public function createEvidence(Dispute $dispute, array $attributes, ?string $filePath = null): DisputeEvidence
    {
        return $this->disputes->createEvidence([
            'dispute_id' => $dispute->id,
            'type' => $attributes['type'],
            'text_content' => $attributes['text_content'] ?? null,
            'file_path' => $filePath,
        ]);
    }
}
