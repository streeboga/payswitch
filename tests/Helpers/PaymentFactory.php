<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

final class PaymentFactory
{
    public static function createMerchantWithConnector(string $connector = 'test', array $credentials = []): array
    {
        $org = Organization::create(['name' => 'Factory Org']);
        $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Factory Merchant']);
        $profile = BusinessProfile::create(['merchant_account_id' => $merchant->id]);

        $rawKey = IdGenerator::apiKey('sandbox');
        ApiKey::create([
            'merchant_account_id' => $merchant->id,
            'key_hash' => hash('sha256', $rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => 'Factory Key',
        ]);

        $mca = MerchantConnectorAccount::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'connector_name' => $connector,
            'connector_type' => 'fiz_operations',
            'connector_account_details' => $credentials ?: ['api_key' => 'factory_key'],
            'payment_methods_enabled' => [['payment_method' => 'card']],
            'test_mode' => true,
        ]);

        return [
            'org' => $org,
            'merchant' => $merchant,
            'profile' => $profile,
            'rawKey' => $rawKey,
            'mca' => $mca,
        ];
    }

    public static function cardData(string $type = 'visa_success'): array
    {
        return match ($type) {
            'visa_success' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
            'visa_3ds' => [
                'card_number' => '4000000000003220',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
            'visa_decline' => [
                'card_number' => '4000000000000002',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
            'mastercard' => [
                'card_number' => '5555555555554444',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ],
            default => throw new \InvalidArgumentException("Unknown card type: {$type}"),
        };
    }
}
