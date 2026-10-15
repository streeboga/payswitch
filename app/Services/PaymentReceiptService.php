<?php

declare(strict_types=1);

namespace App\Services;

use Streeboga\PaymentData\Enums\ReceiptStatus;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\PaymentReceipt;

/**
 * Fiscal receipt state, kept apart from the payment's own (PSW-12: PSP success is not
 * receipt success). A connector account is fiscal when its credentials say `fiscal: true`;
 * the connector then sends the receipt with the payment and a `pending` row is opened here.
 * Only a fiscal signal (callback, sync) moves it on — a payment webhook never does.
 */
final readonly class PaymentReceiptService
{
    public function openIfFiscal(PaymentIntent $payment, MerchantConnectorAccount $mca): ?PaymentReceipt
    {
        $details = $mca->connector_account_details;
        if (($details['fiscal'] ?? false) !== true) {
            return null;
        }

        // One sell receipt per payment: a second session for the same payment reuses it.
        return $payment->receipts()->firstOrCreate(
            ['kind' => 'sell'],
            ['connector' => $mca->connector_name, 'status' => ReceiptStatus::Pending, 'total' => $payment->amount],
        );
    }

    public function markRegistered(PaymentReceipt $receipt, ?string $reference = null, ?string $fiscalUrl = null): bool
    {
        if (! $receipt->status->canBecome(ReceiptStatus::Registered)) {
            return false;
        }

        $receipt->update([
            'status' => ReceiptStatus::Registered, 'provider_reference' => $reference, 'fiscal_url' => $fiscalUrl,
            'error_code' => null, 'error_message' => null, 'registered_at' => now(),
        ]);

        return true;
    }

    public function markFailed(PaymentReceipt $receipt, string $code, string $message): bool
    {
        if (! $receipt->status->canBecome(ReceiptStatus::Failed)) {
            return false;
        }

        $receipt->update(['status' => ReceiptStatus::Failed, 'error_code' => $code, 'error_message' => mb_substr($message, 0, 255)]);

        return true;
    }
}
