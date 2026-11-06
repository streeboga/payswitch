<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Support\Observability\FallbackSignals;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Contracts\RefundStatusQueryable;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\Refund;

/**
 * Сверка pending-возврата с провайдером: только чтение по id, который провайдер уже
 * выдал. Возврат без id (таймаут до ответа) так не сверить — его закрывает уведомление
 * провайдера или человек.
 */
final readonly class RefundReconciliationService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private PaymentIntentRepositoryInterface $paymentRepository,
        private RefundResultService $results,
    ) {}

    /**
     * @return bool Записан ли итог этим вызовом.
     */
    public function reconcile(Refund $refund): bool
    {
        if ($refund->status !== RefundStatus::Pending || ! $refund->connector_refund_id || ! $refund->connector) {
            return false;
        }

        $mca = $this->merchantRepository->findConnectorByMerchantAndName($refund->merchant_account_id, $refund->connector);
        $connector = $mca ? ConnectorFactory::resolve($mca) : null;
        if (! $connector instanceof RefundStatusQueryable) {
            return false;
        }

        try {
            $observed = $connector->getRefundStatus($refund->connector_refund_id);
        } catch (\Throwable $e) {
            app(FallbackSignals::class)->record('refund_reconcile_failure', $e);

            return false;
        }

        $status = match ($observed['status']) {
            'succeeded' => RefundStatus::Succeeded,
            'failed' => RefundStatus::Failed,
            default => null,
        };
        if ($status === null) {
            return false;
        }

        // Итог пишем, только если провайдер говорит про тот же платёж, сумму и валюту:
        // чужой или перепутанный id не должен закрыть наш возврат.
        $payment = $refund->paymentIntent;
        $transactionId = $this->paymentRepository->findLastSuccessfulAttempt($payment)?->connector_transaction_id;
        if ($transactionId === null
            || $observed['payment_transaction_id'] !== $transactionId
            || $observed['amount'] !== $refund->amount
            || strtoupper((string) $observed['currency']) !== strtoupper($refund->currency)) {
            app(FallbackSignals::class)->record('refund_reconcile_mismatch');
            Log::error('Refund reconciliation: provider refund does not match ours, left pending');

            return false;
        }

        $settled = $this->results->settle(
            $refund,
            $status,
            error: ['code' => 'refund_canceled', 'message' => 'The provider cancelled the refund'],
        );
        if ($settled) {
            app(FallbackSignals::class)->succeeded('refund_reconcile');
        }

        return $settled;
    }
}
