<?php

declare(strict_types=1);

namespace App\Support;

use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;

/**
 * Сумма, которую называет провайдер, и что из неё следует.
 *
 * Правило одно на уведомление, sync и синхронный ответ: «успех» без суммы — не оплата.
 */
final class ProviderAmount
{
    /** error_code платежа, который провайдер назвал успешным, не назвав суммы. */
    public const UNCONFIRMED = 'amount_unconfirmed';

    /**
     * Сумма из уведомления или ответа провайдера в минорных единицах; null — не названа.
     *
     * Провайдеры называют её в своей единице (CloudPayments и YooKassa — в рублях,
     * большинство — в минорных), коннектор объявляет в какой. Где она лежит, зависит от
     * провайдера: на верхнем уровне (CloudPayments, T-Bank, Robokassa, RBS, ответы API),
     * в `object` (уведомление YooKassa) или в `data.object` (уведомление Stripe); у
     * YooKassa сама сумма — объект `{value, currency}`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function minor(ConnectorInterface $connector, array $data): ?int
    {
        $holder = $data['object'] ?? $data['data']['object'] ?? $data;
        if (! is_array($holder)) {
            return null;
        }

        $amount = $holder['Amount'] ?? $holder['amount'] ?? $holder['OutSum'] ?? $holder['amount_total'] ?? null;
        if (is_array($amount)) {
            $amount = $amount['value'] ?? null;
        }

        if (! is_numeric($amount)) {
            return null;
        }

        return $connector::capabilities()->amountUnit === AmountUnit::Rubles
            ? (int) round(((float) $amount) * 100)
            : (int) round((float) $amount);
    }

    /**
     * Чем становится платёж, который провайдер назвал оплаченным: succeeded — сумма названа
     * и равна выставленной; processing — не названа (ждём подтверждения суммы);
     * requires_merchant_action — названа другая.
     */
    public static function paidStatus(?int $confirmed, int $billed): PaymentStatus
    {
        return match (true) {
            $confirmed === null => PaymentStatus::Processing,
            $confirmed !== $billed => PaymentStatus::RequiresMerchantAction,
            default => PaymentStatus::Succeeded,
        };
    }

    /**
     * Поля ошибки платежа под статус из paidStatus().
     *
     * @return array{error_code: string|null, error_message: string|null}
     */
    public static function errorFor(PaymentStatus $status): array
    {
        return match ($status) {
            PaymentStatus::Processing => ['error_code' => self::UNCONFIRMED, 'error_message' => 'The provider reported success without an amount; waiting for the amount to be confirmed'],
            PaymentStatus::RequiresMerchantAction => ['error_code' => 'amount_mismatch', 'error_message' => 'The provider charged a different amount or currency than billed'],
            default => ['error_code' => null, 'error_message' => null],
        };
    }
}
