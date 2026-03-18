<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('full payment flow: org → merchant → profile → key → connector → payment → confirm → capture → refund', function () {
    Queue::fake();
    config(['payswitch.admin_api_key' => 'admin_key']);

    // 1. Create Organization
    $orgResponse = $this->postJson('/api/v1/organizations', [
        'data' => ['type' => 'organizations', 'attributes' => ['name' => 'Flow Test Org']],
    ], ['api-key' => 'admin_key']);
    $orgResponse->assertStatus(201);
    $orgId = $orgResponse->json('data.id');

    // 2. Create Merchant Account
    $merchantResponse = $this->postJson('/api/v1/merchants', [
        'data' => ['type' => 'merchants', 'attributes' => ['name' => 'Flow Merchant', 'organization_id' => $orgId]],
    ], ['api-key' => 'admin_key']);
    $merchantResponse->assertStatus(201);
    $merchantKey = $merchantResponse->json('data.id');

    // 3. Create Business Profile
    $profileResponse = $this->postJson('/api/v1/profiles', [
        'data' => ['type' => 'profiles', 'attributes' => [
            'merchant_id' => $merchantKey,
            'webhook_url' => 'https://example.com/webhook',
        ]],
    ], ['api-key' => 'admin_key']);
    $profileResponse->assertStatus(201);
    $profileKey = $profileResponse->json('data.id');

    // 4. Generate API Key
    $keyResponse = $this->postJson("/api/v1/merchants/{$merchantKey}/api-keys", [
        'data' => ['type' => 'api-keys', 'attributes' => ['name' => 'Flow Key']],
    ], ['api-key' => 'admin_key']);
    $keyResponse->assertStatus(201);
    $rawApiKey = $keyResponse->json('data.attributes.api_key');
    expect($rawApiKey)->toStartWith('snd_');

    // 5. Add Test Connector
    $connectorResponse = $this->postJson("/api/v1/merchants/{$merchantKey}/connectors", [
        'data' => ['type' => 'connectors', 'attributes' => [
            'connector_name' => 'test',
            'connector_type' => 'fiz_operations',
            'profile_id' => $profileKey,
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_flow'],
            'payment_methods_enabled' => [['payment_method' => 'card', 'payment_method_types' => [['payment_method_type' => 'credit', 'card_networks' => ['Visa']]]]],
            'test_mode' => true,
        ]],
    ], ['api-key' => 'admin_key']);
    $connectorResponse->assertStatus(201);

    // 6. Create Payment (manual capture)
    $paymentResponse = $this->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => [
            'amount' => 10000,
            'currency' => 'USD',
            'capture_method' => 'manual',
            'description' => 'Full flow test',
        ]],
    ], ['api-key' => $rawApiKey]);
    $paymentResponse->assertStatus(201)
        ->assertJsonPath('data.attributes.status', 'requires_payment_method');
    $paymentId = $paymentResponse->json('data.id');

    // 7. Confirm Payment
    $confirmResponse = $this->postJson("/api/v1/payments/{$paymentId}/confirm", [
        'data' => ['type' => 'payments', 'attributes' => [
            'payment_method' => 'card',
            'payment_method_data' => ['card' => [
                'card_number' => '4242424242424242',
                'card_exp_month' => '12',
                'card_exp_year' => '2030',
                'card_cvc' => '123',
            ]],
        ]],
    ], ['api-key' => $rawApiKey]);
    $confirmResponse->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_capture')
        ->assertJsonPath('data.attributes.amount_capturable', 10000);

    // 8. Capture Payment
    $captureResponse = $this->postJson("/api/v1/payments/{$paymentId}/capture", [
        'data' => ['type' => 'payments', 'attributes' => ['amount_to_capture' => 10000]],
    ], ['api-key' => $rawApiKey]);
    $captureResponse->assertOk()
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount_received', 10000);

    // 9. Create Partial Refund
    $refundResponse = $this->postJson('/api/v1/refunds', [
        'data' => ['type' => 'refunds', 'attributes' => [
            'payment_id' => $paymentId,
            'amount' => 3000,
            'reason' => 'Partial refund test',
        ]],
    ], ['api-key' => $rawApiKey]);
    $refundResponse->assertStatus(201)
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount', 3000)
        ->assertJsonPath('data.attributes.connector', 'test');

    // 10. Verify webhook events were created
    $this->assertDatabaseHas('webhook_events', ['event_type' => 'payment_authorized']);
    $this->assertDatabaseHas('webhook_events', ['event_type' => 'payment_captured']);
    $this->assertDatabaseHas('webhook_events', ['event_type' => 'refund_succeeded']);

    // 11. Verify audit log
    $this->assertDatabaseHas('payment_audit_log', ['new_status' => 'requires_capture']);
    $this->assertDatabaseHas('payment_audit_log', ['new_status' => 'succeeded']);

    // 12. Verify payment attempt was created
    $this->assertDatabaseHas('payment_attempts', [
        'connector' => 'test',
        'status' => 'succeeded',
    ]);
});

test('full flow with automatic capture (create + confirm in one call)', function () {
    Queue::fake();
    config(['payswitch.admin_api_key' => 'admin_key']);

    // Setup merchant (abbreviated)
    $org = $this->postJson('/api/v1/organizations', [
        'data' => ['type' => 'organizations', 'attributes' => ['name' => 'Auto Org']],
    ], ['api-key' => 'admin_key']);

    $merchant = $this->postJson('/api/v1/merchants', [
        'data' => ['type' => 'merchants', 'attributes' => ['name' => 'Auto Merchant', 'organization_id' => $org->json('data.id')]],
    ], ['api-key' => 'admin_key']);

    $profile = $this->postJson('/api/v1/profiles', [
        'data' => ['type' => 'profiles', 'attributes' => ['merchant_id' => $merchant->json('data.id')]],
    ], ['api-key' => 'admin_key']);

    $key = $this->postJson("/api/v1/merchants/{$merchant->json('data.id')}/api-keys", [
        'data' => ['type' => 'api-keys', 'attributes' => ['name' => 'Auto Key']],
    ], ['api-key' => 'admin_key']);

    $this->postJson("/api/v1/merchants/{$merchant->json('data.id')}/connectors", [
        'data' => ['type' => 'connectors', 'attributes' => [
            'connector_name' => 'test', 'connector_type' => 'fiz_operations',
            'profile_id' => $profile->json('data.id'),
            'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test'],
            'test_mode' => true,
        ]],
    ], ['api-key' => 'admin_key']);

    $rawApiKey = $key->json('data.attributes.api_key');

    // Create + confirm in one call (automatic capture)
    $response = $this->postJson('/api/v1/payments', [
        'data' => ['type' => 'payments', 'attributes' => [
            'amount' => 5000, 'currency' => 'EUR', 'confirm' => true,
            'payment_method' => 'card',
            'payment_method_data' => ['card' => [
                'card_number' => '4242424242424242', 'card_exp_month' => '12',
                'card_exp_year' => '2030', 'card_cvc' => '123',
            ]],
        ]],
    ], ['api-key' => $rawApiKey]);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.status', 'succeeded')
        ->assertJsonPath('data.attributes.amount_received', 5000);
});

test('health endpoint returns status without authentication', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonStructure(['status', 'database', 'timestamp']);
});
