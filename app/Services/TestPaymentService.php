<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\DataTransferObjects\Payment\CreatePaymentData;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Models\PaymentIntent;

final readonly class TestPaymentService
{
    public function __construct(
        private PaymentService $paymentService,
        private PaymentConfirmationService $confirmationService,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function createAndConfirm(array $params, int|string $merchantAccountId): PaymentIntent
    {
        $paymentMethod = $params['payment_method'] ?? 'card';
        $paymentMethodData = $params['payment_method_data'] ?? [
            'card' => [
                'card_number' => $params['card_number'] ?? '4242424242424242',
                'card_exp_month' => $params['card_exp_month'] ?? '12',
                'card_exp_year' => $params['card_exp_year'] ?? '2030',
                'card_cvc' => $params['card_cvc'] ?? '123',
            ],
        ];

        $createDto = CreatePaymentData::from([
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? 'USD',
            'capture_method' => CaptureMethod::from($params['capture_method'] ?? 'automatic'),
            'description' => $params['description'] ?? null,
        ]);

        $payment = $this->paymentService->create($createDto, $merchantAccountId);

        $confirmDto = ConfirmPaymentData::from([
            'payment_method' => $paymentMethod,
            'payment_method_data' => $paymentMethodData,
            'connector' => $params['connector_name'] ?? null,
        ]);

        return $this->confirmationService->confirm($payment->key, $confirmDto, $merchantAccountId);
    }
}
