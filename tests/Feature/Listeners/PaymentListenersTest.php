<?php

declare(strict_types=1);

use App\Events\PaymentStatusChanged;
use App\Jobs\DeliverWebhookJob;
use App\Listeners\LogPaymentAudit;
use App\Listeners\SendWebhookNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ]);
});

test('LogPaymentAudit creates activity log entry', function () {
    $event = new PaymentStatusChanged($this->payment, 'processing');
    $listener = new LogPaymentAudit;
    $listener->handle($event);

    $activity = Activity::where('log_name', 'payment')->first();
    expect($activity)->not->toBeNull();
    expect($activity->event)->toBe('status_changed');
    expect($activity->properties['previous_status'])->toBe('processing');
    expect($activity->properties['new_status'])->toBe('succeeded');
    expect($activity->properties['merchant_account_id'])->toBe($this->merchant->id);
});

test('LogPaymentAudit handles null previous status', function () {
    $event = new PaymentStatusChanged($this->payment, null);
    $listener = new LogPaymentAudit;
    $listener->handle($event);

    $activity = Activity::where('log_name', 'payment')->first();
    expect($activity->properties['previous_status'])->toBe('succeeded');
    expect($activity->properties['new_status'])->toBe('succeeded');
});

test('SendWebhookNotification creates webhook event with correct type', function () {
    Queue::fake();

    $event = new PaymentStatusChanged($this->payment, 'processing');
    $listener = app(SendWebhookNotification::class);
    $listener->handle($event);

    $this->assertDatabaseHas('webhook_events', [
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $this->merchant->id,
    ]);
});

test('SendWebhookNotification maps cancelled status correctly', function () {
    Queue::fake();
    $this->payment->update(['status' => PaymentStatus::Cancelled]);

    $event = new PaymentStatusChanged($this->payment, 'requires_payment_method');
    $listener = app(SendWebhookNotification::class);
    $listener->handle($event);

    $this->assertDatabaseHas('webhook_events', ['event_type' => 'payment_cancelled']);
});

test('SendWebhookNotification maps requires_capture to payment_authorized', function () {
    Queue::fake();
    $this->payment->update(['status' => PaymentStatus::RequiresCapture]);

    $event = new PaymentStatusChanged($this->payment, 'requires_payment_method');
    $listener = app(SendWebhookNotification::class);
    $listener->handle($event);

    $this->assertDatabaseHas('webhook_events', ['event_type' => 'payment_authorized']);
});

test('SendWebhookNotification dispatches DeliverWebhookJob', function () {
    Queue::fake();

    $event = new PaymentStatusChanged($this->payment, 'processing');
    $listener = app(SendWebhookNotification::class);
    $listener->handle($event);

    Queue::assertPushed(DeliverWebhookJob::class);
});

test('webhook event content contains payment details', function () {
    Queue::fake();

    $event = new PaymentStatusChanged($this->payment, 'processing');
    $listener = app(SendWebhookNotification::class);
    $listener->handle($event);

    $webhookEvent = WebhookEvent::first();
    expect($webhookEvent->content)->toHaveKey('payment_id')
        ->toHaveKey('status')
        ->toHaveKey('amount');
});
