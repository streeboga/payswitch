<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
        'webhook_url' => 'https://example.com/webhook',
        'webhook_signing_key' => 'test_signing_key_123',
    ]);
});

test('failed delivery increments delivery_attempts and records error', function () {
    Http::fake(['*' => Http::response('server error', 500)]);

    $event = WebhookEvent::create([
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $this->merchant->id,
        'content' => ['payment_id' => 'pay_retry', 'status' => 'succeeded'],
    ]);

    $job = new DeliverWebhookJob($event->id);

    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException) {
        // Expected — triggers retry
    }

    $event->refresh();

    expect($event->delivered)->toBeFalse();
    expect($event->delivery_attempts)->toBe(1);
    expect($event->last_error)->toContain('500');
});

test('already delivered event is not re-sent', function () {
    Http::fake();

    $event = WebhookEvent::create([
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $this->merchant->id,
        'content' => ['payment_id' => 'pay_done', 'status' => 'succeeded'],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);

    $job = new DeliverWebhookJob($event->id);
    app()->call([$job, 'handle']);

    Http::assertNothingSent();

    // Delivery attempts should remain unchanged
    expect($event->fresh()->delivery_attempts)->toBe(1);
});
