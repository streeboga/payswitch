<?php

declare(strict_types=1);

use App\Jobs\ReconcileWebhookEventsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
});

function reconcilePayment(PaymentStatus $status, int $minutesAgo = 5): PaymentIntent
{
    test()->travelTo(now()->subMinutes($minutesAgo));
    $payment = PaymentIntent::create([
        'merchant_account_id' => test()->merchant->id,
        'amount' => 77700,
        'currency' => 'RUB',
        'status' => $status,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
    ]);
    test()->travelBack();

    return $payment;
}

function runReconcile(): void
{
    app()->call([new ReconcileWebhookEventsJob, 'handle']);
}

test('succeeded payment without an event gets exactly one, and only once', function () {
    Log::spy();
    $payment = reconcilePayment(PaymentStatus::Succeeded);

    runReconcile();

    $events = WebhookEvent::where('payment_intent_id', $payment->id)->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->event_type)->toBe('payment_succeeded')
        ->and($events[0]->content['status'])->toBe('succeeded');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, $payment->key))->once();

    runReconcile();

    expect(WebhookEvent::where('payment_intent_id', $payment->id)->count())->toBe(1);
});

test('event for an earlier status does not count as notified', function () {
    $payment = reconcilePayment(PaymentStatus::Succeeded);
    WebhookEvent::create([
        'event_type' => 'payment_status_changed',
        'merchant_account_id' => $this->merchant->id,
        'payment_intent_id' => $payment->id,
        'content' => ['payment_id' => $payment->key, 'status' => 'processing'],
    ]);
    WebhookEvent::create([
        'event_type' => 'refund_succeeded',
        'merchant_account_id' => $this->merchant->id,
        'payment_intent_id' => $payment->id,
        'content' => ['payment_id' => $payment->key, 'status' => 'succeeded'],
    ]);

    runReconcile();

    expect(WebhookEvent::where('payment_intent_id', $payment->id)->where('event_type', 'payment_succeeded')->count())->toBe(1);
});

test('leaves alone notified, too fresh, too old and non-final payments', function () {
    $notified = reconcilePayment(PaymentStatus::Failed);
    WebhookEvent::create([
        'event_type' => 'payment_status_changed',
        'merchant_account_id' => $this->merchant->id,
        'payment_intent_id' => $notified->id,
        'content' => ['payment_id' => $notified->key, 'status' => 'failed'],
    ]);
    reconcilePayment(PaymentStatus::Succeeded, minutesAgo: 1);
    reconcilePayment(PaymentStatus::Succeeded, minutesAgo: 60 * 24 * 8);
    reconcilePayment(PaymentStatus::Processing);
    reconcilePayment(PaymentStatus::RequiresCustomerAction);

    runReconcile();

    expect(WebhookEvent::count())->toBe(1);
});

test('a second reconcile is not queued while the first has not finished', function () {
    // withoutOverlapping() в расписании держит мьютекс только на постановку:
    // два прогона параллельно слали бы одни и те же платежи дважды.
    Queue::fake();

    ReconcileWebhookEventsJob::dispatch();
    ReconcileWebhookEventsJob::dispatch();

    Queue::assertPushed(ReconcileWebhookEventsJob::class, 1);
});
