<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PaymentStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;

/**
 * Credits a platform wallet when a payment succeeds.
 *
 * Opt-in per payment: the merchant puts `wallet_key` into the payment
 * metadata. Idempotency key is derived from the payment key, so a redelivered
 * PSP webhook (or a job retry) never credits twice — the Wallets service
 * returns the original transaction instead.
 */
final class DepositToWallet implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300, 900];

    public function handle(PaymentStatusChanged $event): void
    {
        $payment = $event->payment;

        if ($payment->status !== PaymentStatus::Succeeded) {
            return;
        }

        $walletKey = $payment->metadata['wallet_key'] ?? null;
        if (! is_string($walletKey) || $walletKey === '') {
            return;
        }

        $url = config('payswitch.wallets.url');
        $secret = config('payswitch.wallets.internal_secret');

        if (! $url || ! $secret) {
            Log::warning('Wallet deposit skipped: wallets service is not configured', [
                'payment_id' => $payment->key,
            ]);

            return;
        }

        // ponytail: the merchant owns the wallet_key, so currency match is their
        // problem — checking it here would cost an extra round trip per payment.
        $response = Http::timeout(15)
            ->withHeaders(['X-Internal-Secret' => $secret])
            ->post(rtrim((string) $url, '/').'/internal/wallets/deposit', [
                'wallet_key' => $walletKey,
                'amount' => $payment->amount_received ?? $payment->amount,
                'idempotency_key' => 'psw_'.$payment->key,
                'reference_type' => 'payment',
                'reference_id' => $payment->key,
                'metadata' => [
                    'currency' => $payment->currency,
                    'connector' => $payment->connector,
                ],
            ]);

        if ($response->failed()) {
            Log::error('Wallet deposit failed', [
                'payment_id' => $payment->key,
                'wallet_key' => $walletKey,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        $response->throw();

        Log::info('Wallet credited from payment', [
            'payment_id' => $payment->key,
            'wallet_key' => $walletKey,
            'amount' => $payment->amount_received ?? $payment->amount,
        ]);
    }
}
