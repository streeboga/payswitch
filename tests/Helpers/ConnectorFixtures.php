<?php

declare(strict_types=1);

namespace Tests\Helpers;

final class ConnectorFixtures
{
    public static function yookassaPaymentSucceeded(string $id = 'yk_123', int $amount = 5000): array
    {
        return [
            'id' => $id,
            'status' => 'succeeded',
            'amount' => [
                'value' => number_format($amount / 100, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'payment_method' => ['type' => 'bank_card'],
            'created_at' => now()->toIso8601String(),
        ];
    }

    public static function yookassaPending3ds(string $id = 'yk_3ds', string $redirectUrl = 'https://yookassa.ru/3ds'): array
    {
        return [
            'id' => $id,
            'status' => 'pending',
            'confirmation' => [
                'type' => 'redirect',
                'confirmation_url' => $redirectUrl,
            ],
        ];
    }

    public static function yookassaRefundSucceeded(string $id = 'yk_ref_1', int $amount = 1000): array
    {
        return [
            'id' => $id,
            'status' => 'succeeded',
            'amount' => [
                'value' => number_format($amount / 100, 2, '.', ''),
                'currency' => 'RUB',
            ],
        ];
    }

    public static function cloudpaymentsSuccess(int $transactionId = 504735239, int $amount = 5000): array
    {
        return [
            'Success' => true,
            'Message' => null,
            'Model' => [
                'TransactionId' => $transactionId,
                'Amount' => $amount / 100,
                'Currency' => 'RUB',
                'Status' => 'Completed',
            ],
        ];
    }

    public static function cloudpayments3ds(int $transactionId = 504735239): array
    {
        return [
            'Success' => false,
            'Message' => '3-D Secure is required',
            'Model' => [
                'TransactionId' => $transactionId,
                'PaReq' => 'eJxVUdtuwjAM/ZWqz...',
                'AcsUrl' => 'https://bank.example.com/3ds',
            ],
        ];
    }

    public static function stripePaymentIntentSucceeded(string $id = 'pi_test123', int $amount = 5000): array
    {
        return [
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => $amount,
            'currency' => 'usd',
        ];
    }

    public static function stripeRequiresAction(string $id = 'pi_3ds', string $redirectUrl = 'https://stripe.com/3ds'): array
    {
        return [
            'id' => $id,
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'redirect_to_url',
                'redirect_to_url' => ['url' => $redirectUrl],
            ],
        ];
    }

    public static function stripeCardDeclined(): array
    {
        return [
            'error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'message' => 'Your card was declined.',
            ],
        ];
    }
}
