<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\Payment\CreatePaymentData;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Models\PaymentIntent;

final readonly class TestPaymentService
{
    public function __construct(
        private PaymentService $paymentService,
    ) {}

    public function createAndConfirm(array $params, int|string $merchantAccountId): PaymentIntent
    {
        $dto = CreatePaymentData::from([
            'amount' => $params['amount'],
            'currency' => $params['currency'] ?? 'USD',
            'capture_method' => CaptureMethod::from($params['capture_method'] ?? 'automatic'),
            'confirm' => true,
            'payment_method' => $params['payment_method'] ?? 'card',
            'payment_method_data' => $params['payment_method_data'] ?? [
                'card' => [
                    'card_number' => $params['card_number'] ?? '4242424242424242',
                    'card_exp_month' => '12',
                    'card_exp_year' => '2030',
                    'card_cvc' => '123',
                ],
            ],
        ]);

        return $this->paymentService->create($dto, $merchantAccountId);
    }
}
