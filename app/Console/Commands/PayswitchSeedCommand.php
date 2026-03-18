<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserRole;
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
    protected $signature = 'payswitch:seed
        {--user= : Existing user ID or email to bind as admin}
        {--fresh : Drop existing demo data and recreate}';

    protected $description = 'Create demo organization, merchant, profile, connectors, API key, and admin user binding';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->warn('Wiping existing demo data...');
            Organization::where('name', 'Demo Organization')->each(function ($org) {
                $org->merchantAccounts->each(function ($m) {
                    $m->delete();
                });
                $org->delete();
            });
        }

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
            'key_hash' => hash('sha256', $rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => 'Demo API Key',
        ]);
        $this->newLine();
        $this->warn('=== SAVE THIS API KEY — IT WILL NOT BE SHOWN AGAIN ===');
        $this->line("API Key: <comment>{$rawKey}</comment>");
        $this->warn('=====================================================');

        // 5. Test Connector (always works, no external API)
        MerchantConnectorAccount::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'connector_name' => 'test',
            'connector_type' => 'fiz_operations',
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'test'],
            'payment_methods_enabled' => [
                ['payment_method' => 'card'],
            ],
            'test_mode' => true,
        ]);
        $this->line('Test Connector: <info>created</info>');

        // 6. Stripe Connector (test mode — replace key for real usage)
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
        $this->line('Stripe Connector: <info>created (test mode)</info>');

        // 7. Bind user as admin
        $user = $this->resolveUser();
        if ($user) {
            UserRole::updateOrCreate(
                ['user_id' => $user->id, 'organization_id' => $org->id],
                ['role' => 'admin'],
            );
            $this->line("Admin user: <info>{$user->email}</info> (id={$user->id})");
        } else {
            $this->warn('No user bound. Use --user=<email|id> or create a user first.');
        }

        $this->newLine();
        $this->info('Demo environment ready!');
        $this->line("Merchant key for dashboard: <comment>{$merchant->key}</comment>");
        $this->line('Example: curl -H "api-key: '.$rawKey.'" http://localhost:8000/api/v1/payments');

        return Command::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $userOption = $this->option('user');

        if ($userOption) {
            return is_numeric($userOption)
                ? User::find((int) $userOption)
                : User::where('email', $userOption)->first();
        }

        // Auto-detect: use the first user if only one exists
        if (User::count() === 1) {
            return User::first();
        }

        return null;
    }
}
