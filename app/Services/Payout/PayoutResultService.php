<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\DataTransferObjects\Payout\PayoutExternalResult;
use App\Enums\PayoutStatus;
use App\Jobs\DeliverWebhookJob;
use App\Models\Payout;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use App\Repositories\Eloquent\PayoutRepository;
use App\Support\CanonicalRequest;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Exceptions\PaymentException;

final readonly class PayoutResultService
{
    public function __construct(private PayoutRepository $repository, private WebhookEventRepositoryInterface $events) {}

    /** Trusted channel adapter only: signature/readback verification must happen before this call. */
    public function apply(string $key, int $merchantId, string $projectId, PayoutExternalResult $result): Payout
    {
        return DB::transaction(function () use ($key, $merchantId, $projectId, $result) {
            $this->repository->lockMerchant($merchantId);
            $payout = $this->repository->find($key, $merchantId, $projectId);
            $recipient = $this->repository->recipientFor($payout);
            if ($result->amount !== $payout->amount || $result->currency !== $payout->currency || $result->precision !== $payout->precision
                || $result->recipientVersionKey !== $recipient->key || $result->providerReference === '' || $result->evidenceReference === '' || $result->eventId === '') {
                throw new PaymentException('External evidence does not match payout', 'payout_evidence_mismatch', 'invalid_request_error', 409);
            }
            $attributes = [
                'event_id' => $result->eventId, 'attempt_key' => $result->attemptKey, 'execution_absent' => $result->executionAbsent, 'status' => $result->status->value,
                'provider_reference' => $result->providerReference, 'amount' => $result->amount,
                'currency' => $result->currency, 'recipient_version_key' => $result->recipientVersionKey,
                'precision' => $result->precision, 'related_reference' => $result->relatedReference,
                'evidence_reference' => $result->evidenceReference, 'occurred_at' => $result->occurredAt->toIso8601String(),
            ];
            $hash = CanonicalRequest::hash($attributes);
            $previous = $this->repository->eventHash($payout, $result->eventId);
            if ($previous !== null) {
                if ($previous !== $hash) {
                    throw new PaymentException('External event content conflict', 'idempotency_conflict', 'invalid_request_error', 409);
                }

                return $payout;
            }
            $attempt = $this->repository->attempt($payout);
            if ($result->attemptKey !== $attempt->key || ($result->status === PayoutStatus::Failed && ! $result->executionAbsent)) {
                throw new PaymentException('Result requires the original attempt and proof of non-execution for failure', 'payout_evidence_mismatch', 'invalid_request_error', 409);
            }
            if (($result->status === PayoutStatus::Reversed && ($attempt->provider_reference === null || $result->relatedReference !== $attempt->provider_reference))
                || ($result->status !== PayoutStatus::Reversed && $attempt->provider_reference !== null && $attempt->provider_reference !== $result->providerReference)) {
                throw new PaymentException('External attempt reference conflict', 'payout_evidence_mismatch', 'invalid_request_error', 409);
            }
            if ($payout->status === $result->status && in_array($payout->status, [PayoutStatus::Succeeded, PayoutStatus::Reversed, PayoutStatus::Failed], true)) {
                return $payout;
            }
            $from = $payout->status;
            $pending = [PayoutStatus::Processing, PayoutStatus::PendingConfirmation, PayoutStatus::AwaitingBankSignature];
            $allowed = in_array($from, $pending, true)
                && in_array($result->status, [...$pending, PayoutStatus::Succeeded, PayoutStatus::Failed], true);
            $allowed = $allowed || ($from === PayoutStatus::Succeeded && $result->status === PayoutStatus::Reversed);
            // Preserve terminal history; late acceptance must not regress success/reversal.
            if (! $allowed) {
                throw new PaymentException('External result conflicts with payout state', 'invalid_payout_state', 'invalid_request_error', 409);
            }
            $this->repository->claimExternalReference($payout, $result->providerReference);
            $this->repository->appendEvent($payout, [
                ...$attributes, 'occurred_at' => $result->occurredAt,
                'request_hash' => $hash, 'received_at' => now(),
            ]);
            $this->repository->updateAttempt($attempt, ['status' => $result->status, 'provider_reference' => $result->status === PayoutStatus::Reversed ? $attempt->provider_reference : $result->providerReference]);
            $this->repository->update($payout, [
                'status' => $result->status,
                'succeeded_at' => $result->status === PayoutStatus::Succeeded ? $result->occurredAt : $payout->succeeded_at,
            ]);
            $event = $this->events->create([
                'event_type' => 'payout.'.$result->status->value,
                'merchant_account_id' => $merchantId,
                'content' => [
                    'payout_id' => $key, 'project_id' => $projectId, 'operation_id' => $payout->operation_id,
                    'precision' => $payout->precision, ...$attributes, 'received_at' => now()->toIso8601String(),
                ],
            ]);
            DeliverWebhookJob::dispatch($event->id)->afterCommit();

            return $payout;
        });
    }
}
