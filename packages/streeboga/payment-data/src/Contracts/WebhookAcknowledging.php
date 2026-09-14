<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Contracts;

/**
 * A connector whose provider reads the webhook response body, not just the status code.
 *
 * Most providers treat 200 as "received" and ignore the body. A few decide the fate of
 * the payment by what we write back: CloudPayments rejects the charge unless the check
 * notification is answered with its own success code. For those, the driver — not the
 * receiver — says what the body has to be.
 */
interface WebhookAcknowledging
{
    /**
     * Body the provider expects in the webhook response.
     *
     * @param  string|null  $refusal  Why we will not let the operation proceed — 'amount'
     *                                when the notified sum or currency is not the one we
     *                                billed, 'expired' when the payment ran out of time,
     *                                'unacceptable' otherwise — or null to allow it.
     * @return array<string, mixed>
     */
    public function webhookAck(?string $refusal): array;
}
