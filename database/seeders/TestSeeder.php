<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoutingRuleType;
use App\Enums\UserRole as UserRoleEnum;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\RoutingRule;
use Streeboga\PaymentData\Support\IdGenerator;

class TestSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $org = Organization::create(['name' => 'Test Org']);

        UserRole::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'role' => UserRoleEnum::Admin,
        ]);

        $merchant = MerchantAccount::create([
            'org_id' => $org->id,
            'name' => 'Test Merchant',
        ]);

        $profile = BusinessProfile::create([
            'merchant_account_id' => $merchant->id,
            'webhook_url' => 'https://test-merchant.example.com/webhooks',
        ]);

        $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));
        ApiKey::create([
            'merchant_account_id' => $merchant->id,
            'key_hash' => hash('sha256', $rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => 'Default key for Test Merchant',
        ]);

        MerchantConnectorAccount::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'connector_name' => 'dummy_connector',
            'connector_type' => 'payment_processor',
            'connector_account_details' => ['api_key' => 'test_key_e2e'],
            'test_mode' => true,
            'disabled' => false,
        ]);

        RoutingRule::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'type' => RoutingRuleType::VolumeSplit,
            'name' => 'Default routing for Test Merchant',
            'rules' => [
                ['connector' => 'dummy_connector', 'weight' => 100],
            ],
            'active' => true,
            'priority' => 1,
        ]);
    }
}
