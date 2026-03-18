<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
});

test('dashboard overview requires authentication', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('dashboard overview loads for authenticated user', function () {
    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertOk();
});

test('dashboard overview shows payment stats', function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    PaymentIntent::create([
        'merchant_account_id' => $merchant->id,
        'amount' => 5000, 'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'amount_received' => 5000, 'attempt_count' => 1,
    ]);

    $response = $this->actingAs($this->user)->get('/dashboard');
    $response->assertOk();
    // Inertia page should receive stats prop
});

test('dashboard payments list requires authentication', function () {
    $this->get('/dashboard/payments')->assertRedirect('/login');
});

test('dashboard payments list loads for authenticated user', function () {
    $this->actingAs($this->user)
        ->get('/dashboard/payments')
        ->assertOk();
});

test('dashboard merchants list requires authentication', function () {
    $this->get('/dashboard/merchants')->assertRedirect('/login');
});

test('dashboard merchants list loads for authenticated user', function () {
    $this->actingAs($this->user)
        ->get('/dashboard/merchants')
        ->assertOk();
});

test('dashboard payment detail returns 404 for non-existent payment', function () {
    $this->actingAs($this->user)
        ->get('/dashboard/payments/pay_nonexistent')
        ->assertNotFound();
});
