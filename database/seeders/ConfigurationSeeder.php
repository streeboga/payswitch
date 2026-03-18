<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\PaymentMethod;
use Streeboga\PaymentData\Models\RoutingRule;
use Streeboga\PaymentData\Models\WebhookEvent;
use Streeboga\PaymentData\Support\IdGenerator;

class ConfigurationSeeder extends Seeder
{
    /**
     * Seed configuration data for merchant_id=2 (Demo Merchant, org_id=2).
     */
    public function run(): void
    {
        $merchantId = 2;
        $profileId = 2; // pro_01KM11R7RKD1JTVJ0DKH7NR18R

        $this->seedCustomers($merchantId);
        $this->seedRoutingRules($merchantId, $profileId);
        $this->seedWebhookEvents($merchantId, $profileId);
    }

    private function seedCustomers(int $merchantId): void
    {
        $customers = [
            ['name' => 'Иван Петров', 'email' => 'ivan.petrov@example.com', 'phone' => '+79161234567', 'phone_country_code' => 'RU', 'description' => 'VIP-клиент, регулярные платежи'],
            ['name' => 'Maria Garcia', 'email' => 'maria.garcia@example.com', 'phone' => '+34612345678', 'phone_country_code' => 'ES', 'description' => 'European customer'],
            ['name' => 'John Smith', 'email' => 'john.smith@example.com', 'phone' => '+14155551234', 'phone_country_code' => 'US', 'description' => null],
            ['name' => 'Анна Сидорова', 'email' => 'anna.sidorova@example.com', 'phone' => null, 'phone_country_code' => null, 'description' => 'Корпоративный клиент'],
            ['name' => 'Chen Wei', 'email' => 'chen.wei@example.com', 'phone' => '+8613800138000', 'phone_country_code' => 'CN', 'description' => 'International partner'],
            ['name' => 'Olga Novikova', 'email' => 'olga@example.com', 'phone' => '+79031112233', 'phone_country_code' => 'RU', 'description' => null],
            ['name' => 'Test Customer No Email', 'email' => null, 'phone' => '+79999999999', 'phone_country_code' => 'RU', 'description' => 'Тестовый клиент без email'],
        ];

        foreach ($customers as $data) {
            $customer = Customer::create([
                'key' => IdGenerator::customerId(),
                'merchant_account_id' => $merchantId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'phone_country_code' => $data['phone_country_code'],
                'description' => $data['description'],
                'metadata' => $data['email'] ? ['source' => 'dashboard', 'tier' => 'standard'] : null,
            ]);

            // Add payment methods to some customers
            if ($data['email'] && in_array($data['phone_country_code'], ['RU', 'US'])) {
                $this->seedPaymentMethods($customer, $merchantId);
            }
        }
    }

    private function seedPaymentMethods(Customer $customer, int $merchantId): void
    {
        $cards = [
            ['brand' => 'visa', 'last4' => '4242', 'holder' => $customer->name, 'exp_month' => '12', 'exp_year' => '2028'],
            ['brand' => 'mastercard', 'last4' => '8210', 'holder' => $customer->name, 'exp_month' => '06', 'exp_year' => '2027'],
        ];

        foreach ($cards as $i => $card) {
            PaymentMethod::create([
                'key' => IdGenerator::paymentMethodId(),
                'customer_id' => $customer->id,
                'merchant_account_id' => $merchantId,
                'type' => 'card',
                'card_last4' => $card['last4'],
                'card_brand' => $card['brand'],
                'card_exp_month' => $card['exp_month'],
                'card_exp_year' => $card['exp_year'],
                'card_holder_name' => $card['holder'],
                'connector_name' => 'stripe',
                'connector_token' => 'tok_'.bin2hex(random_bytes(12)),
                'is_default' => $i === 0,
            ]);
        }

        // Set default payment method
        $defaultPm = $customer->paymentMethods()->where('is_default', true)->first();
        if ($defaultPm) {
            $customer->update(['default_payment_method_id' => $defaultPm->key]);
        }
    }

    private function seedRoutingRules(int $merchantId, int $profileId): void
    {
        // Priority routing rule
        RoutingRule::create([
            'key' => IdGenerator::routingRuleId(),
            'merchant_account_id' => $merchantId,
            'business_profile_id' => $profileId,
            'type' => 'priority',
            'name' => 'Основной приоритет',
            'rules' => [
                ['connector' => 'stripe', 'priority' => 1],
                ['connector' => 'cloudpayments', 'priority' => 2],
                ['connector' => 'test', 'priority' => 3],
            ],
            'active' => true,
            'priority' => 10,
        ]);

        // Rule-based routing
        RoutingRule::create([
            'key' => IdGenerator::routingRuleId(),
            'merchant_account_id' => $merchantId,
            'business_profile_id' => $profileId,
            'type' => 'rule_based',
            'name' => 'Роутинг по валюте',
            'rules' => [
                [
                    'conditions' => [
                        ['field' => 'currency', 'operator' => 'equals', 'value' => 'RUB'],
                    ],
                    'connector' => 'cloudpayments',
                ],
                [
                    'conditions' => [
                        ['field' => 'currency', 'operator' => 'equals', 'value' => 'USD'],
                    ],
                    'connector' => 'stripe',
                ],
            ],
            'active' => true,
            'priority' => 20,
        ]);

        // Volume split routing
        RoutingRule::create([
            'key' => IdGenerator::routingRuleId(),
            'merchant_account_id' => $merchantId,
            'business_profile_id' => $profileId,
            'type' => 'volume_split',
            'name' => 'A/B тест коннекторов',
            'rules' => [
                ['connector' => 'stripe', 'percentage' => 70],
                ['connector' => 'cloudpayments', 'percentage' => 30],
            ],
            'active' => false,
            'priority' => 30,
        ]);

        // Another rule-based (by amount)
        RoutingRule::create([
            'key' => IdGenerator::routingRuleId(),
            'merchant_account_id' => $merchantId,
            'business_profile_id' => null,
            'type' => 'rule_based',
            'name' => 'Роутинг по сумме',
            'rules' => [
                [
                    'conditions' => [
                        ['field' => 'amount', 'operator' => 'greater_than', 'value' => 100000],
                    ],
                    'connector' => 'stripe',
                ],
                [
                    'conditions' => [
                        ['field' => 'amount', 'operator' => 'less_than_or_equal', 'value' => 100000],
                    ],
                    'connector' => 'cloudpayments',
                ],
            ],
            'active' => true,
            'priority' => 15,
        ]);
    }

    private function seedWebhookEvents(int $merchantId, int $profileId): void
    {
        // Get some payment IDs to reference
        $paymentIds = PaymentIntent::where('merchant_account_id', $merchantId)
            ->pluck('id', 'key')
            ->take(5);

        $eventTypes = [
            'payment.succeeded',
            'payment.failed',
            'payment.authorized',
            'payment.captured',
            'payment.cancelled',
            'payment.processing',
            'refund.succeeded',
            'refund.failed',
        ];

        $i = 0;
        foreach ($paymentIds as $paymentKey => $paymentId) {
            // Delivered event
            WebhookEvent::create([
                'key' => IdGenerator::eventId(),
                'event_type' => $eventTypes[$i % count($eventTypes)],
                'merchant_account_id' => $merchantId,
                'business_profile_id' => $profileId,
                'payment_intent_id' => $paymentId,
                'content' => [
                    'type' => 'payment',
                    'id' => $paymentKey,
                    'attributes' => ['status' => 'succeeded', 'amount' => rand(1000, 50000), 'currency' => 'USD'],
                ],
                'delivered' => true,
                'delivery_attempts' => 1,
                'next_retry_at' => null,
                'last_error' => null,
            ]);

            // Failed event
            WebhookEvent::create([
                'key' => IdGenerator::eventId(),
                'event_type' => $eventTypes[($i + 1) % count($eventTypes)],
                'merchant_account_id' => $merchantId,
                'business_profile_id' => $profileId,
                'payment_intent_id' => $paymentId,
                'content' => [
                    'type' => 'payment',
                    'id' => $paymentKey,
                    'attributes' => ['status' => 'failed', 'amount' => rand(500, 30000), 'currency' => 'RUB'],
                ],
                'delivered' => false,
                'delivery_attempts' => 3,
                'next_retry_at' => now()->addMinutes(10),
                'last_error' => 'Connection timeout: webhook endpoint did not respond within 30s',
            ]);

            $i++;
        }

        // Some events without payment reference
        WebhookEvent::create([
            'key' => IdGenerator::eventId(),
            'event_type' => 'payment.succeeded',
            'merchant_account_id' => $merchantId,
            'business_profile_id' => $profileId,
            'payment_intent_id' => null,
            'content' => ['type' => 'test', 'id' => 'test_ping', 'attributes' => ['status' => 'ok']],
            'delivered' => true,
            'delivery_attempts' => 1,
            'next_retry_at' => null,
            'last_error' => null,
        ]);

        WebhookEvent::create([
            'key' => IdGenerator::eventId(),
            'event_type' => 'refund.succeeded',
            'merchant_account_id' => $merchantId,
            'business_profile_id' => $profileId,
            'payment_intent_id' => null,
            'content' => ['type' => 'refund', 'id' => 'ref_test', 'attributes' => ['status' => 'succeeded', 'amount' => 5000]],
            'delivered' => false,
            'delivery_attempts' => 5,
            'next_retry_at' => now()->addHours(6),
            'last_error' => 'HTTP 500: Internal Server Error',
        ]);
    }
}
