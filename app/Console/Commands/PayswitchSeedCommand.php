<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RoutingRuleType;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentAuditLog;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Models\RoutingRule;
use Streeboga\PaymentData\Models\WebhookEvent;
use Streeboga\PaymentData\Support\IdGenerator;

final class PayswitchSeedCommand extends Command
{
    protected $signature = 'payswitch:seed
        {--user= : Existing user ID or email to bind as admin}
        {--fresh : Drop existing demo data and recreate}';

    protected $description = 'Create demo organization, merchant, profile, connectors, API key, customers, payments, refunds, webhooks, and admin user binding';

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

        // 7. Customers
        $customers = collect([
            ['name' => 'Иван Петров', 'email' => 'ivan@example.com', 'phone' => '+79991234567', 'phone_country_code' => 'RU'],
            ['name' => 'Anna Smith', 'email' => 'anna@example.com', 'phone' => '+15551234567', 'phone_country_code' => 'US'],
            ['name' => 'Demo Customer', 'email' => 'demo@example.com'],
        ])->map(fn (array $data) => Customer::create([
            'merchant_account_id' => $merchant->id,
            ...$data,
        ]));
        $this->line("Customers: <info>{$customers->count()} created</info>");

        // 8. Routing Rule
        RoutingRule::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'type' => RoutingRuleType::Priority,
            'name' => 'Default Priority',
            'rules' => ['connectors' => ['test', 'stripe']],
            'active' => true,
            'priority' => 10,
        ]);
        $this->line('Routing Rule: <info>created</info>');

        // 9. Payments
        $statuses = [
            ...array_fill(0, 6, PaymentStatus::Succeeded),
            ...array_fill(0, 3, PaymentStatus::Failed),
            ...array_fill(0, 2, PaymentStatus::Processing),
            ...array_fill(0, 2, PaymentStatus::Cancelled),
            PaymentStatus::RequiresCapture,
        ];
        $currencies = ['RUB', 'USD', 'EUR'];
        $connectors = ['test', 'stripe'];

        $payments = collect($statuses)->map(function (PaymentStatus $status, int $i) use ($merchant, $profile, $customers, $currencies, $connectors) {
            $currency = $currencies[$i % count($currencies)];
            $amount = random_int(1000, 500000);
            $connector = $connectors[$i % count($connectors)];
            $captureMethod = $i % 3 === 0 ? CaptureMethod::Manual : CaptureMethod::Automatic;
            $customer = $customers[$i % $customers->count()];
            $createdAt = now()->subHours(random_int(1, 720));

            $paymentData = [
                'merchant_account_id' => $merchant->id,
                'business_profile_id' => $profile->id,
                'amount' => $amount,
                'currency' => $currency,
                'status' => $status,
                'capture_method' => $captureMethod,
                'customer_id' => $customer->key,
                'connector' => $connector,
                'attempt_count' => 1,
                'description' => "Demo payment #{$i}",
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if ($status === PaymentStatus::Succeeded) {
                $paymentData['amount_received'] = $amount;
                $paymentData['net_amount'] = $amount;
            }

            if ($status === PaymentStatus::RequiresCapture) {
                $paymentData['amount_capturable'] = $amount;
            }

            if ($status === PaymentStatus::Failed) {
                $paymentData['error_code'] = 'card_declined';
                $paymentData['error_message'] = 'The card was declined by the issuer.';
            }

            if ($status === PaymentStatus::Cancelled) {
                $paymentData['cancellation_reason'] = 'requested_by_customer';
            }

            $payment = PaymentIntent::create($paymentData);

            // Payment attempt
            PaymentAttempt::create([
                'payment_intent_id' => $payment->id,
                'connector' => $connector,
                'connector_transaction_id' => 'txn_demo_'.str($payment->key)->after('pay_'),
                'status' => $status->value,
                'amount' => $amount,
                'error_code' => $status === PaymentStatus::Failed ? 'card_declined' : null,
                'error_message' => $status === PaymentStatus::Failed ? 'The card was declined by the issuer.' : null,
            ]);

            // Audit logs
            PaymentAuditLog::create([
                'payment_intent_id' => $payment->id,
                'merchant_account_id' => $merchant->id,
                'action' => 'payment.created',
                'previous_status' => null,
                'new_status' => PaymentStatus::RequiresPaymentMethod->value,
                'actor' => 'api',
                'created_at' => $createdAt,
            ]);

            PaymentAuditLog::create([
                'payment_intent_id' => $payment->id,
                'merchant_account_id' => $merchant->id,
                'action' => 'payment.status_changed',
                'previous_status' => PaymentStatus::Processing->value,
                'new_status' => $status->value,
                'actor' => 'connector:'.$connector,
                'created_at' => $createdAt->copy()->addSeconds(random_int(1, 30)),
            ]);

            return $payment;
        });
        $this->line("Payments: <info>{$payments->count()} created</info>");

        // 10. Refunds on first 3 succeeded payments
        $succeededPayments = $payments->filter(fn (PaymentIntent $p) => $p->status === PaymentStatus::Succeeded)->values();
        $refundConfigs = [
            ['status' => RefundStatus::Succeeded, 'reason' => 'requested_by_customer'],
            ['status' => RefundStatus::Failed, 'reason' => 'duplicate'],
            ['status' => RefundStatus::Pending, 'reason' => 'fraudulent'],
        ];

        foreach ($refundConfigs as $idx => $config) {
            $payment = $succeededPayments[$idx];

            $refundData = [
                'payment_intent_id' => $payment->id,
                'merchant_account_id' => $merchant->id,
                'business_profile_id' => $profile->id,
                'amount' => intdiv($payment->amount, 2),
                'currency' => $payment->currency,
                'status' => $config['status'],
                'reason' => $config['reason'],
                'connector' => $payment->connector,
                'connector_refund_id' => 'ref_demo_'.($idx + 1),
            ];

            if ($config['status'] === RefundStatus::Failed) {
                $refundData['error_code'] = 'charge_already_refunded';
                $refundData['error_message'] = 'The charge has already been refunded.';
            }

            Refund::create($refundData);
        }
        $this->line('Refunds: <info>3 created</info>');

        // 11. Webhook events for first 3 succeeded payments
        foreach ($succeededPayments->take(3) as $payment) {
            WebhookEvent::create([
                'event_type' => 'payment.succeeded',
                'merchant_account_id' => $merchant->id,
                'business_profile_id' => $profile->id,
                'payment_intent_id' => $payment->id,
                'content' => ['payment_key' => $payment->key, 'amount' => $payment->amount, 'currency' => $payment->currency],
                'delivered' => true,
                'delivery_attempts' => 1,
            ]);
        }
        $this->line('Webhook Events: <info>3 created</info>');

        // 12. Bind user as admin
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
