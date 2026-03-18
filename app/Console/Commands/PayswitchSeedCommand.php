<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

final class PayswitchSeedCommand extends Command
{
    protected $signature = 'payswitch:seed';

    protected $description = 'Create demo organization, merchant, profile, API key, and Stripe connector';

    public function handle(): int
    {
        $this->info('Creating demo payswitch environment...');
        $this->newLine();

        // 1. Organization
        $org = Organization::create(['name' => 'Demo Organization']);
        $this->line("Organization: <info>{$org->key}</info>");

        // 2. Merchant Account
        $merchant = MerchantAccount::create([
            'org_id' => $org->id,
            'name' => 'Demo Merchant',
        ]);
        $this->line("Merchant: <info>{$merchant->key}</info>");
        $this->line("Publishable Key: <info>{$merchant->publishable_key}</info>");

        // 3. Business Profile
        $profile = BusinessProfile::create([
            'merchant_account_id' => $merchant->id,
            'webhook_url' => 'https://httpbin.org/post',
        ]);
        $this->line("Business Profile: <info>{$profile->key}</info>");

        // 4. API Key
        $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));
        ApiKey::create([
            'merchant_account_id' => $merchant->id,
            'key_hash' => bcrypt($rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => 'Demo API Key',
        ]);
        $this->newLine();
        $this->warn('=== SAVE THIS API KEY — IT WILL NOT BE SHOWN AGAIN ===');
        $this->line("API Key: <comment>{$rawKey}</comment>");
        $this->warn('=====================================================');

        // 5. Stripe Connector (test mode)
        MerchantConnectorAccount::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'connector_name' => 'stripe',
            'connector_type' => 'fiz_operations',
            'connector_account_details' => Crypt::encryptString(json_encode([
                'auth_type' => 'HeaderKey',
                'api_key' => 'sk_test_REPLACE_WITH_YOUR_KEY',
            ])),
            'payment_methods_enabled' => [
                [
                    'payment_method' => 'card',
                    'payment_method_types' => [
                        ['payment_method_type' => 'credit', 'card_networks' => ['Visa', 'Mastercard']],
                    ],
                ],
            ],
            'test_mode' => true,
        ]);
        $this->line("Stripe Connector: <info>created (test mode)</info>");

        $this->newLine();
        $this->info('Demo environment ready! Use the API key above to make requests.');
        $this->line('Example: curl -H "api-key: '.$rawKey.'" http://localhost:8000/api/v1/payments');

        return Command::SUCCESS;
    }
}
