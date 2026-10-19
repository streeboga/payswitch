<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;

/**
 * Where to send a buyer coming back from Robokassa (SuccessURL / FailURL).
 *
 * The page is not evidence of payment: the status is read from our own storage, the query
 * only says which payment to look up. Shp_payment_id (an unguessable key Robokassa echoes
 * back) must name a payment of this connector's merchant and its InvId must match, so a
 * guessed InvId reveals nothing.
 */
final readonly class RobokassaReturnService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private PaymentIntentRepositoryInterface $paymentRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array{payment_id: string, status: string, return_url: string|null}|null null = unknown payment
     */
    public function resolve(string $merchantKey, string $mcaKey, array $params): ?array
    {
        $merchant = $this->merchantRepository->findMerchantByKeyOrNull($merchantKey);
        $mca = $merchant ? $this->merchantRepository->findConnectorByMerchantAndKeyOrNull($merchant->id, $mcaKey) : null;
        $key = $params['Shp_payment_id'] ?? null;
        if ($merchant === null || $mca === null || $mca->connector_name !== 'robokassa' || ! is_string($key) || $key === '') {
            return null;
        }

        $payment = $this->paymentRepository->findByKeyOrNull($key, $merchant->id);
        $invId = $params['InvId'] ?? null;
        if ($payment === null || ! is_scalar($invId) || (string) $payment->id !== (string) $invId) {
            return null;
        }

        $returnUrl = $payment->return_url;
        if (is_string($returnUrl) && $returnUrl !== '') {
            $returnUrl .= (str_contains($returnUrl, '?') ? '&' : '?').http_build_query(['payment_id' => $payment->key, 'status' => $payment->status->value]);
        }

        return ['payment_id' => $payment->key, 'status' => $payment->status->value, 'return_url' => $returnUrl ?: null];
    }
}
