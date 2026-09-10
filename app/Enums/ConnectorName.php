<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\Enums\HasColor;
use App\Contracts\Enums\HasIcon;
use App\Contracts\Enums\HasLabel;

enum ConnectorName: string implements HasColor, HasIcon, HasLabel
{
    case Stripe = 'stripe';
    case CloudPayments = 'cloudpayments';
    case YooKassa = 'yookassa';
    case Sberbank = 'sberbank';
    case AlfaBank = 'alfabank';
    case TBank = 'tbank';
    case Robokassa = 'robokassa';
    case Tochka = 'tochka';
    case Test = 'test';
    case TestSbp = 'test_sbp';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::CloudPayments => 'CloudPayments',
            self::YooKassa => 'ЮKassa',
            self::Sberbank => 'Сбербанк',
            self::AlfaBank => 'Альфа-Банк',
            self::TBank => 'Т-Банк',
            self::Robokassa => 'Робокасса',
            self::Tochka => 'Точка',
            self::Test => 'Тестовый',
            self::TestSbp => 'Тестовый СБП',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Stripe => 'primary',
            self::CloudPayments => 'info',
            self::YooKassa => 'success',
            self::Sberbank => 'success',
            self::AlfaBank => 'danger',
            self::TBank => 'warning',
            self::Robokassa => 'info',
            self::Tochka => 'warning',
            self::Test => 'gray',
            self::TestSbp => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Stripe => 'heroicon-o-credit-card',
            self::CloudPayments => 'heroicon-o-cloud',
            self::YooKassa => 'heroicon-o-currency-dollar',
            self::Sberbank => 'heroicon-o-building-library',
            self::AlfaBank => 'heroicon-o-building-office-2',
            self::TBank => 'heroicon-o-banknotes',
            self::Robokassa => 'heroicon-o-shopping-cart',
            self::Tochka => 'heroicon-o-building-office',
            self::Test => 'heroicon-o-beaker',
            self::TestSbp => 'heroicon-o-qr-code',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
