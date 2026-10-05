<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\Contracts\PayoutAuthorizationVerifier;
use App\DataTransferObjects\Payout\CreatePayoutData;
use App\Enums\PayoutStatus;
use App\Jobs\DeliverWebhookJob;
use App\Models\Payout;
use App\Models\PayoutRecipientVersion;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use App\Repositories\Eloquent\PayoutRepository;
use App\Support\CanonicalRequest;
use App\Support\MoneyConverter;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Exceptions\PaymentException;

final readonly class PayoutService
{
    public function __construct(private PayoutRepository $repository, private PayoutAuthorizationVerifier $authorization, private WebhookEventRepositoryInterface $events) {}

    /** Trusted onboarding adapter only; verification must precede this call.
     * @param  array<string, string>  $details
     */
    public function recordVerifiedRecipient(int $merchantId, string $projectId, string $recipientReference, string $verificationReference, array $details, string $verificationPurpose): PayoutRecipientVersion
    {
        if ($projectId === '' || $verificationReference === '' || $recipientReference === '' || $details === [] || $verificationPurpose !== 'receive_payout') {
            throw new PaymentException('Verified recipient snapshot required', 'recipient_not_verified', 'invalid_request_error', 400);
        }

        return $this->repository->createRecipient([
            'merchant_account_id' => $merchantId, 'project_id' => $projectId,
            'recipient_reference' => $recipientReference, 'verification_reference' => $verificationReference, 'verification_purpose' => $verificationPurpose,
            'details' => $details, 'details_hash' => CanonicalRequest::hash($details), 'created_at' => now(),
        ]);
    }

    public function create(CreatePayoutData $data, int $merchantId): Payout
    {
        $amount = MoneyConverter::atomicToMinor($data->atomic_amount, $data->currency, $data->precision, $data->precision);
        if ($amount <= 0 || $data->project_id === '' || $data->operation_id === '' || $data->idempotency_key === '' || $data->channel === '') {
            throw new PaymentException('Payout identity and positive amount required', 'invalid_payout', 'invalid_request_error', 400);
        }

        foreach ([$data->project_id, $data->operation_id, $data->settlement_id, $data->idempotency_key, $data->channel] as $reference) {
            if ($reference === '' || strlen($reference) > 128) {
                throw new PaymentException('Payout references must contain 1–128 bytes', 'invalid_payout', 'invalid_request_error', 400);
            }
        }
        if ($data->purpose === '' || strlen($data->purpose) > 1000) {
            throw new PaymentException('Payout purpose must contain 1–1000 bytes', 'invalid_payout', 'invalid_request_error', 400);
        }

        return DB::transaction(function () use ($data, $merchantId, $amount) {
            $this->repository->lockMerchant($merchantId);
            $hash = CanonicalRequest::hash($data->toArray());
            $existing = $this->repository->existing($merchantId, $data->project_id, $data->idempotency_key, $data->operation_id);
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new PaymentException('Payout identity content conflict', 'idempotency_conflict', 'invalid_request_error', 409);
                }

                return $existing;
            }
            $recipient = $this->repository->recipient($data->recipient_version_key, $merchantId, $data->project_id);

            return $this->repository->create([
                ...array_diff_key($data->toArray(), array_flip(['recipient_version_key', 'atomic_amount'])),
                'merchant_account_id' => $merchantId, 'recipient_version_id' => $recipient->id,
                'amount' => $amount, 'request_hash' => $hash, 'status' => PayoutStatus::RequiresApproval,
            ]);
        });
    }

    public function confirm(string $key, int $merchantId, string $projectId): Payout
    {
        return DB::transaction(function () use ($key, $merchantId, $projectId) {
            $payout = $this->repository->find($key, $merchantId, $projectId);
            if ($payout->status === PayoutStatus::Ready) {
                return $payout;
            }
            if (! in_array($payout->status, [PayoutStatus::RequiresApproval, PayoutStatus::Failed], true)) {
                throw new PaymentException('Payout cannot be approved in this state', 'invalid_payout_state', 'invalid_request_error', 409);
            }
            $authorization = $this->authorization->verify($payout);
            if ($authorization->reserveReference === '' || $authorization->authorizationReference === '' || $authorization->approvedBy === '') {
                throw new PaymentException('Incomplete Genesis authorization', 'payout_authorization_invalid', 'invalid_request_error', 409);
            }

            return $this->repository->update($payout, [
                'status' => PayoutStatus::Ready, 'approved_by' => $authorization->approvedBy,
                'reserve_reference' => $authorization->reserveReference, 'authorization_reference' => $authorization->authorizationReference,
            ]);
        });
    }

    public function cancel(string $key, int $merchantId, string $projectId): Payout
    {
        return DB::transaction(function () use ($key, $merchantId, $projectId) {
            $payout = $this->repository->find($key, $merchantId, $projectId);
            if ($payout->status === PayoutStatus::Cancelled) {
                return $payout;
            }
            if (! in_array($payout->status, [PayoutStatus::RequiresApproval, PayoutStatus::Ready], true)) {
                throw new PaymentException('Cancellation after dispatch is not safe', 'invalid_payout_state', 'invalid_request_error', 409);
            }

            $payout = $this->repository->update($payout, ['status' => PayoutStatus::Cancelled]);
            $event = $this->events->create([
                'event_type' => 'payout.cancelled', 'merchant_account_id' => $merchantId,
                'content' => ['payout_id' => $key, 'project_id' => $projectId, 'operation_id' => $payout->operation_id,
                    'reserve_reference' => $payout->reserve_reference, 'status' => 'cancelled', 'received_at' => now()->toIso8601String()],
            ]);
            DeliverWebhookJob::dispatch($event->id)->afterCommit();

            return $payout;
        });
    }
}
