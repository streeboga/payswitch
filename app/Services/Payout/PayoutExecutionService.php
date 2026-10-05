<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\Contracts\PayoutAuthorizationVerifier;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Repositories\Eloquent\PayoutRepository;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Exceptions\PaymentException;

final readonly class PayoutExecutionService
{
    public function __construct(
        private PayoutRepository $repository,
        private PayoutAuthorizationVerifier $authorization,
        private PayoutChannels $channels,
        private PayoutResultService $results,
    ) {}

    public function execute(string $key, int $merchantId, string $projectId): Payout
    {
        $claim = DB::transaction(function () use ($key, $merchantId, $projectId) {
            $payout = $this->repository->find($key, $merchantId, $projectId);
            if (in_array($payout->status, [PayoutStatus::Processing, PayoutStatus::PendingConfirmation, PayoutStatus::AwaitingBankSignature, PayoutStatus::Succeeded, PayoutStatus::Reversed], true)) {
                return [$payout, null, null, null];
            }
            if ($payout->status !== PayoutStatus::Ready) {
                throw new PaymentException('Payout is not ready', 'invalid_payout_state', 'invalid_request_error', 409);
            }
            $channel = $this->channels->resolve($payout->channel);
            $recipient = $this->repository->recipientFor($payout);
            if ($recipient->verification_purpose !== 'receive_payout') {
                throw new PaymentException('Payout-purpose verification required', 'recipient_not_verified', 'invalid_request_error', 409);
            }
            $channel->assertSupported($payout, $recipient);
            // Recheck the live reserve immediately before claim. No merchant-supplied approval.
            $authorization = $this->authorization->verify($payout);
            if ($authorization->authorizationReference !== $payout->authorization_reference
                || $authorization->reserveReference !== $payout->reserve_reference
                || $authorization->approvedBy !== $payout->approved_by) {
                throw new PaymentException('Genesis authorization changed', 'payout_authorization_invalid', 'invalid_request_error', 409);
            }
            $attempt = $this->repository->createAttempt($payout);
            $this->repository->update($payout, ['status' => PayoutStatus::Processing]);

            return [$payout, $attempt, $recipient, $channel];
        });
        [$payout, $attempt, $recipient, $channel] = $claim;
        if ($attempt === null || $recipient === null || $channel === null) {
            return $payout;
        }
        try {
            $result = $channel->execute($payout, $attempt, $recipient);
        } catch (\Throwable) {
            $result = null;
        }
        if ($result !== null) {
            return $this->results->apply($key, $merchantId, $projectId, $result);
        }

        return DB::transaction(function () use ($key, $merchantId, $projectId) {
            $current = $this->repository->find($key, $merchantId, $projectId);
            if ($current->status === PayoutStatus::Processing) {
                $this->repository->updateAttempt($this->repository->attempt($current), ['status' => PayoutStatus::PendingConfirmation]);
                $this->repository->update($current, ['status' => PayoutStatus::PendingConfirmation]);
            }

            return $current;
        });
    }

    public function sync(string $key, int $merchantId, string $projectId): Payout
    {
        $payout = $this->repository->find($key, $merchantId, $projectId);
        $attempt = $this->repository->attempt($payout);
        // Sync remains available after success to observe later reversals.
        $result = $this->channels->resolve($payout->channel)->sync($payout, $attempt);

        return $result === null ? $payout : $this->results->apply($key, $merchantId, $projectId, $result);
    }
}
