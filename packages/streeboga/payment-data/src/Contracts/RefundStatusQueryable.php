<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Contracts;

/**
 * A connector whose provider can be asked what became of a refund it already accepted.
 *
 * Read-only: the driver looks the refund up by the provider's own id and never sends it
 * again. Whatever it cannot read or recognise is `unknown` — not `failed`: the money may
 * have gone.
 */
interface RefundStatusQueryable
{
    /**
     * @param  string  $reference  The provider's refund id (`refunds.connector_refund_id`).
     * @return array{status: 'succeeded'|'failed'|'pending'|'unknown', amount: int|null, currency: string|null, payment_transaction_id: string|null}
     *                                                                                                                                               `amount` in minor units; `payment_transaction_id` is the provider's id of the refunded payment.
     */
    public function getRefundStatus(string $reference): array;
}
