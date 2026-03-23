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

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Users
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@payswitch.test',
        ]);

        $operator = User::factory()->create([
            'name' => 'Operator',
            'email' => 'operator@payswitch.test',
        ]);

        // Organizations
        $acme = Organization::create(['name' => 'Acme Corp']);
        $techstart = Organization::create(['name' => 'TechStart LLC']);
        $global = Organization::create(['name' => 'Global Payments Inc']);

        // User roles: admin on all 3 orgs, operator on first org only
        foreach ([$acme, $techstart, $global] as $org) {
            UserRole::create([
                'user_id' => $admin->id,
                'organization_id' => $org->id,
                'role' => UserRoleEnum::Admin,
            ]);
        }

        UserRole::create([
            'user_id' => $operator->id,
            'organization_id' => $acme->id,
            'role' => UserRoleEnum::Operator,
        ]);

        // Merchants per organization
        $merchantMap = [
            $acme->id => ['Web Store', 'Mobile App'],
            $techstart->id => ['SaaS Platform'],
            $global->id => ['EU Store', 'US Store', 'Asia Store'],
        ];

        foreach ($merchantMap as $orgId => $merchantNames) {
            foreach ($merchantNames as $merchantName) {
                $merchant = MerchantAccount::create([
                    'org_id' => $orgId,
                    'name' => $merchantName,
                ]);

                $this->seedMerchantDependencies($merchant, $merchantName);
            }
        }
    }

    private function seedMerchantDependencies(MerchantAccount $merchant, string $merchantName): void
    {
        // Business profile with webhook
        $profile = BusinessProfile::create([
            'merchant_account_id' => $merchant->id,
            'name' => 'Default',
            'webhook_url' => 'https://'.str_replace(' ', '-', strtolower($merchantName)).'.example.com/webhooks',
        ]);

        // API key
        $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));
        ApiKey::create([
            'merchant_account_id' => $merchant->id,
            'key_hash' => hash('sha256', $rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => "Default key for {$merchantName}",
        ]);

        // Test connector
        MerchantConnectorAccount::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'connector_name' => 'dummy_connector',
            'connector_type' => 'payment_processor',
            'connector_account_details' => ['api_key' => 'test_key_'.strtolower(str_replace(' ', '_', $merchantName))],
            'test_mode' => true,
            'disabled' => false,
        ]);

        // Routing rule
        RoutingRule::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'type' => RoutingRuleType::VolumeSplit,
            'name' => "Default routing for {$merchantName}",
            'rules' => [
                ['connector' => 'dummy_connector', 'weight' => 100],
            ],
            'active' => true,
            'priority' => 1,
        ]);
    }
}
