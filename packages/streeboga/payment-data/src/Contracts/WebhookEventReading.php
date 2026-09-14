<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Contracts;

/**
 * A connector whose provider does not say what happened in a `type` field.
 *
 * The receiver reads `type` by default. YooKassa puts the event in `event` (and `type` is
 * always "notification"), RBS in `operation` plus a success flag, Robokassa calls its
 * ResultURL only on success and says nothing at all, CloudPayments tells check, pay,
 * fail, refund and cancel apart only by which fields are present. For those the driver
 * reads the event, in the vocabulary its own mapWebhookEventToStatus() understands.
 */
interface WebhookEventReading
{
    /** A pre-charge question ("may I charge this?") rather than a report of a charge. */
    public const CHECK = 'payment.check';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function webhookEventType(array $payload): string;
}
