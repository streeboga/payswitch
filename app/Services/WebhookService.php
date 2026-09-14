<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WebhookEventType;
use App\Jobs\DeliverWebhookJob;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;

final readonly class WebhookService
{
    public function __construct(
        private WebhookEventRepositoryInterface $webhookRepository,
    ) {}

    public function dispatchForPayment(PaymentIntent $payment): void
    {
        $eventType = match ($payment->status) {
            PaymentStatus::Succeeded => $payment->capture_method === CaptureMethod::Manual
                ? WebhookEventType::PaymentCaptured->value
                : WebhookEventType::PaymentSucceeded->value,
            PaymentStatus::Cancelled => WebhookEventType::PaymentCancelled->value,
            PaymentStatus::RequiresCapture => WebhookEventType::PaymentAuthorized->value,
            default => WebhookEventType::PaymentStatusChanged->value,
        };

        $webhookEvent = $this->webhookRepository->create([
            'event_type' => $eventType,
            'merchant_account_id' => $payment->merchant_account_id,
            // Доставка берёт адрес из профиля платежа, а не любого профиля мерчанта.
            'business_profile_id' => $payment->business_profile_id,
            'payment_intent_id' => $payment->id,
            'content' => [
                'payment_id' => $payment->key,
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                // Зачислять надо подтверждённое провайдером, а не запрошенное:
                // частичное списание иначе уехало бы получателю полной суммой.
                'amount_received' => $payment->amount_received,
                'currency' => $payment->currency,
                // Назначение платежа кладёт тот, кто его завёл. Без него
                // приёмник не отличит пополнение кошелька от оплаты счёта и
                // обязан догадываться по своим таблицам.
                //
                // Своего типа «пополнение» у payswitch нет и не будет: тип
                // события описывает судьбу платежа, а не то, чьи это деньги
                // и что с ними делать. Это решает Genesis (Р28).
                'metadata' => $payment->metadata ?? [],
            ],
        ]);

        DeliverWebhookJob::dispatch($webhookEvent->id);
    }

    /**
     * Уведомление мерчанту о возврате. Один источник для возврата через API и
     * возврата, о котором сообщил провайдер (сделан в его кабинете).
     */
    public function dispatchForRefund(Refund $refund, PaymentIntent $payment): void
    {
        $webhookEvent = $this->webhookRepository->create([
            'event_type' => $refund->status === RefundStatus::Succeeded
                ? WebhookEventType::RefundSucceeded->value
                : WebhookEventType::RefundFailed->value,
            'merchant_account_id' => $refund->merchant_account_id,
            'business_profile_id' => $payment->business_profile_id,
            'payment_intent_id' => $payment->id,
            'content' => [
                'refund_id' => $refund->key,
                'payment_id' => $payment->key,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'status' => $refund->status->value,
            ],
        ]);

        DeliverWebhookJob::dispatch($webhookEvent->id);
    }
}
