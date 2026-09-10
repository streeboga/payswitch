<?php

declare(strict_types=1);

use App\Events\PaymentStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $merchant->id,
        'amount' => 17502,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresCustomerAction,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
        'return_url' => 'https://invoice.example.test/checkout/success',
    ]);
});

test('подтверждение тестового платежа поднимает событие для вебхуков', function () {
    Event::fake([PaymentStatusChanged::class]);

    $this->postJson("/api/v1/test-psp/{$this->payment->key}/complete", ['action' => 'approve'])
        ->assertSuccessful();

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Succeeded);

    Event::assertDispatched(PaymentStatusChanged::class, function (PaymentStatusChanged $e) {
        return $e->payment->key === $this->payment->key
            && $e->payment->status === PaymentStatus::Succeeded;
    });
});

test('отклонение тестового платежа тоже поднимает событие', function () {
    Event::fake([PaymentStatusChanged::class]);

    $this->postJson("/api/v1/test-psp/{$this->payment->key}/complete", ['action' => 'decline'])
        ->assertSuccessful();

    expect($this->payment->refresh()->status)->toBe(PaymentStatus::Failed);
    Event::assertDispatched(PaymentStatusChanged::class);
});
