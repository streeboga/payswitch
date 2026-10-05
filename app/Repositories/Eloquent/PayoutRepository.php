<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\PayoutRecipientVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Exceptions\PaymentException;

final readonly class PayoutRepository
{
    /** @return Collection<int, Payout> */
    public function pending(int $merchantId, string $projectId, int $limit): Collection
    {
        return Payout::where('merchant_account_id', $merchantId)->where('project_id', $projectId)
            ->whereIn('status', ['processing', 'pending_confirmation', 'awaiting_bank_signature'])
            ->orderBy('updated_at')->limit($limit)->get();
    }

    public function lockMerchant(int $merchantId): void
    {
        DB::table('merchant_accounts')->where('id', $merchantId)->lockForUpdate()->firstOrFail();
    }

    public function find(string $key, int $merchantId, string $projectId): Payout
    {
        return Payout::where('merchant_account_id', $merchantId)->where('project_id', $projectId)->where('key', $key)->lockForUpdate()->firstOrFail();
    }

    public function existing(int $merchantId, string $projectId, string $idempotencyKey, string $operationId): ?Payout
    {
        return Payout::where('merchant_account_id', $merchantId)->where('project_id', $projectId)
            ->where(fn ($q) => $q->where('idempotency_key', $idempotencyKey)->orWhere('operation_id', $operationId))->first();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Payout
    {
        return Payout::create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Payout $payout, array $attributes): Payout
    {
        $payout->update($attributes);

        return $payout;
    }

    public function recipient(string $key, int $merchantId, string $projectId): PayoutRecipientVersion
    {
        return PayoutRecipientVersion::where('merchant_account_id', $merchantId)->where('project_id', $projectId)->where('key', $key)->firstOrFail();
    }

    public function recipientFor(Payout $payout): PayoutRecipientVersion
    {
        return PayoutRecipientVersion::where('id', $payout->recipient_version_id)
            ->where('merchant_account_id', $payout->merchant_account_id)->where('project_id', $payout->project_id)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function createRecipient(array $attributes): PayoutRecipientVersion
    {
        return PayoutRecipientVersion::create($attributes);
    }

    public function createAttempt(Payout $payout): PayoutAttempt
    {
        return PayoutAttempt::create(['payout_id' => $payout->id, 'channel' => $payout->channel, 'status' => PayoutStatus::Processing]);
    }

    public function attempt(Payout $payout): PayoutAttempt
    {
        return PayoutAttempt::where('payout_id', $payout->id)->latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function updateAttempt(PayoutAttempt $attempt, array $attributes): void
    {
        $attempt->update($attributes);
    }

    public function eventHash(Payout $payout, string $eventId): ?string
    {
        return DB::table('payout_external_events')->where('payout_id', $payout->id)->where('event_id', $eventId)->value('request_hash');
    }

    public function claimExternalReference(Payout $payout, string $reference): void
    {
        $scope = ['merchant_account_id' => $payout->merchant_account_id, 'channel' => $payout->channel, 'provider_reference' => $reference];
        $existing = DB::table('payout_external_references')->where($scope)->first();
        if ($existing !== null && $existing->payout_id !== $payout->id) {
            throw new PaymentException('External transfer already belongs to another payout', 'payout_evidence_conflict', 'invalid_request_error', 409);
        }
        if ($existing === null) {
            DB::table('payout_external_references')->insert([...$scope, 'payout_id' => $payout->id]);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function appendEvent(Payout $payout, array $attributes): void
    {
        DB::table('payout_external_events')->insert(['payout_id' => $payout->id, ...$attributes]);
    }
}
