<?php

declare(strict_types=1);

use App\Enums\DisputeStatus;
use App\Enums\DisputeType;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    UserRole::create(['user_id' => $this->user->id, 'organization_id' => $org->id, 'role' => 'admin']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];

    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'net_amount' => 9700, 'amount_capturable' => 0, 'amount_received' => 10000,
        'currency' => 'USD', 'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic', 'authentication_type' => 'no_three_ds',
        'session_expiry' => now()->addMinutes(15),
    ]);
});

test('disputes list returns paginated response', function () {
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.type', 'disputes')
        ->assertJsonPath('data.0.attributes.status', 'opened');
});

test('dispute detail returns json:api resource', function () {
    $dispute = Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/disputes/{$dispute->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'disputes')
        ->assertJsonPath('data.id', $dispute->key);
});

test('disputes scoped to merchant', function () {
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);

    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    $otherPayment = PaymentIntent::create([
        'merchant_account_id' => $otherMerchant->id, 'amount' => 5000, 'net_amount' => 4850,
        'amount_capturable' => 0, 'amount_received' => 5000, 'currency' => 'USD',
        'status' => PaymentStatus::Succeeded, 'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds', 'session_expiry' => now()->addMinutes(15),
    ]);
    Dispute::create([
        'payment_intent_id' => $otherPayment->id, 'merchant_account_id' => $otherMerchant->id,
        'amount' => 5000, 'currency' => 'USD', 'type' => DisputeType::Inquiry, 'status' => DisputeStatus::Opened,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('disputes require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/disputes', $this->headers);
    $response->assertUnauthorized();
});

test('disputes list filters by status', function () {
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 5000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Won,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes?filter[status]=won', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.status', 'won')
        ->assertJsonPath('data.0.attributes.amount', 5000);
});

test('disputes list filters by type', function () {
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 7500, 'currency' => 'EUR', 'type' => DisputeType::Fraud, 'status' => DisputeStatus::EvidenceRequired,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes?filter[type]=fraud', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.type', 'fraud')
        ->assertJsonPath('data.0.attributes.currency', 'EUR')
        ->assertJsonPath('data.0.attributes.amount', 7500);
});

test('dispute detail returns all concrete attributes', function () {
    $dispute = Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 15000, 'currency' => 'EUR', 'type' => DisputeType::Fraud,
        'status' => DisputeStatus::EvidenceRequired,
        'reason_code' => '10.4', 'reason_description' => 'Other Fraud',
        'deadline_at' => '2026-04-01 00:00:00',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/disputes/{$dispute->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'disputes')
        ->assertJsonPath('data.id', $dispute->key)
        ->assertJsonPath('data.attributes.amount', 15000)
        ->assertJsonPath('data.attributes.currency', 'EUR')
        ->assertJsonPath('data.attributes.type', 'fraud')
        ->assertJsonPath('data.attributes.status', 'evidence_required')
        ->assertJsonPath('data.attributes.reason_code', '10.4')
        ->assertJsonPath('data.attributes.reason_description', 'Other Fraud')
        ->assertJsonPath('data.attributes.payment_id', $this->payment->key);
});

test('dispute detail returns 404 for nonexistent key', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes/dsp_nonexistent', $this->headers);

    $response->assertNotFound();
});

test('submit dispute evidence with text content', function () {
    $dispute = Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback,
        'status' => DisputeStatus::EvidenceRequired,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/dashboard/disputes/{$dispute->key}/evidence", [
            'type' => 'customer_communication',
            'text_content' => 'Customer agreed to the charge via email on 2026-03-10.',
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'dispute-evidences')
        ->assertJsonPath('data.attributes.type', 'customer_communication')
        ->assertJsonPath('data.attributes.text_content', 'Customer agreed to the charge via email on 2026-03-10.');

    $this->assertDatabaseHas('dispute_evidences', [
        'dispute_id' => $dispute->id,
        'type' => 'customer_communication',
        'text_content' => 'Customer agreed to the charge via email on 2026-03-10.',
        'file_path' => null,
    ]);
});

test('submit dispute evidence with file upload', function () {
    Storage::fake('local');

    $dispute = Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback,
        'status' => DisputeStatus::EvidenceRequired,
    ]);

    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/dashboard/disputes/{$dispute->key}/evidence", [
            'type' => 'receipt',
            'file' => $file,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'dispute-evidences')
        ->assertJsonPath('data.attributes.type', 'receipt');

    $this->assertDatabaseHas('dispute_evidences', [
        'dispute_id' => $dispute->id,
        'type' => 'receipt',
    ]);

    $evidence = DisputeEvidence::where('dispute_id', $dispute->id)->first();
    expect($evidence->file_path)->not->toBeNull();
    Storage::disk('local')->assertExists($evidence->file_path);
});

test('submit evidence requires type field', function () {
    $dispute = Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback,
        'status' => DisputeStatus::EvidenceRequired,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/dashboard/disputes/{$dispute->key}/evidence", [
            'text_content' => 'Some evidence text',
        ], $this->headers);

    $response->assertUnprocessable()
        ->assertJsonFragment(['detail' => 'The type field is required.']);
});

test('disputes list respects page size parameter', function () {
    Model::preventLazyLoading(false);

    for ($i = 0; $i < 5; $i++) {
        Dispute::create([
            'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
            'amount' => 1000 * ($i + 1), 'currency' => 'USD',
            'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
        ]);
    }

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes?page[size]=2', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 5)
        ->assertJsonCount(2, 'data');
});

test('disputes list with combined status and type filters', function () {
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 10000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Opened,
    ]);
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 8000, 'currency' => 'USD', 'type' => DisputeType::Inquiry, 'status' => DisputeStatus::Opened,
    ]);
    Dispute::create([
        'payment_intent_id' => $this->payment->id, 'merchant_account_id' => $this->merchant->id,
        'amount' => 6000, 'currency' => 'USD', 'type' => DisputeType::Chargeback, 'status' => DisputeStatus::Won,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/disputes?filter[status]=opened&filter[type]=chargeback', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.attributes.type', 'chargeback')
        ->assertJsonPath('data.0.attributes.status', 'opened')
        ->assertJsonPath('data.0.attributes.amount', 10000);
});
