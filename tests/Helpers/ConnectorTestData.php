<?php

declare(strict_types=1);

namespace Tests\Helpers;

final class ConnectorTestData
{
    public static function card(string $scenario = 'success'): array
    {
        return match ($scenario) {
            'success' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
            'decline' => [
                'card_number' => '4000000000000002',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
            'insufficient_funds' => [
                'card_number' => '4000000000009995',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
            '3ds' => [
                'card_number' => '4000000000003220',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
            default => throw new \InvalidArgumentException("Unknown card scenario: {$scenario}"),
        };
    }

    public static function paymentParams(array $overrides = []): array
    {
        return array_merge([
            'amount' => 5000,
            'currency' => 'RUB',
            'payment_method' => 'card',
            'payment_method_data' => ['card' => self::card()],
            'description' => 'Test payment',
            'payment_id' => 'pay_01TEST',
        ], $overrides);
    }

    public static function webhookFixture(string $name): array
    {
        $path = __DIR__.'/../Fixtures/Webhooks/'.$name.'.json';

        return json_decode(file_get_contents($path), true);
    }
}
