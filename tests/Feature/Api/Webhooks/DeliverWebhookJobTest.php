<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use App\Services\WebhookService;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\WebhookEvent;
use Streeboga\PaymentData\Support\WebhookSigner;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
        'webhook_url' => 'https://merchant.example.com/webhook',
    ]);
    $this->event = WebhookEvent::create([
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $this->merchant->id,
        'content' => ['payment_id' => 'pay_test', 'status' => 'succeeded'],
    ]);
});

test('delivers webhook successfully', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $job = new DeliverWebhookJob($this->event->id);
    app()->call([$job, 'handle']);

    expect($this->event->fresh()->delivered)->toBeTrue();
    expect($this->event->fresh()->delivery_attempts)->toBe(1);
});

test('marks delivery attempt on failure', function () {
    Http::fake(['*' => Http::response('error', 500)]);

    $job = new DeliverWebhookJob($this->event->id);

    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException $e) {
        // Expected — triggers retry
    }

    expect($this->event->fresh()->delivered)->toBeFalse();
    expect($this->event->fresh()->delivery_attempts)->toBe(1);
});

test('skips already delivered event', function () {
    $this->event->update(['delivered' => true]);
    Http::fake();

    $job = new DeliverWebhookJob($this->event->id);
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
});

test('no webhook url: records the error, warns and retries instead of dropping the event', function () {
    $this->profile->update(['webhook_url' => null]);
    Http::fake();
    Log::spy();

    $job = new DeliverWebhookJob($this->event->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
    $event = $this->event->fresh();
    expect($event->delivered)->toBeFalse()
        ->and($event->delivery_attempts)->toBe(1)
        ->and($event->last_error)->toBe('No webhook URL configured');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, $event->key));
});

test('exhausted attempts: job fails loudly and keeps the real error', function () {
    Http::fake(['*' => Http::response('upstream down', 500)]);
    Log::spy();
    $this->event->update(['delivery_attempts' => config('payswitch.webhook.max_attempts') - 1]);

    $failed = false;
    Queue::failing(function (JobFailed $e) use (&$failed) {
        $failed = true;
    });

    DeliverWebhookJob::dispatchSync($this->event->id);

    $event = $this->event->fresh();
    expect($failed)->toBeTrue()
        ->and($event->delivered)->toBeFalse()
        ->and($event->delivery_attempts)->toBe(config('payswitch.webhook.max_attempts'))
        ->and($event->last_error)->toContain('HTTP 500: upstream down')
        ->and($event->last_error)->toContain('permanently failed');
    Log::shouldHaveReceived('error')->withArgs(
        fn ($message, $context = []) => str_contains($message, $event->key)
            && str_contains($context['last_error'] ?? '', 'HTTP 500: upstream down'),
    );
});

test('delivers to the profile of the event, not an arbitrary merchant profile', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $second = BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
        'webhook_url' => 'https://second.example.com/webhook',
    ]);
    $this->event->update(['business_profile_id' => $second->id]);

    app()->call([new DeliverWebhookJob($this->event->id), 'handle']);

    Http::assertSent(fn ($request) => $request->url() === 'https://second.example.com/webhook');
});

test('event without profile falls back to the oldest merchant profile', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    BusinessProfile::create([
        'merchant_account_id' => $this->merchant->id,
        'webhook_url' => 'https://second.example.com/webhook',
    ]);

    app()->call([new DeliverWebhookJob($this->event->id), 'handle']);

    Http::assertSent(fn ($request) => $request->url() === 'https://merchant.example.com/webhook');
});

test('dispatchForPayment records the business profile of the payment', function () {
    Queue::fake();
    $second = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $second->id,
        'amount' => 5000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'attempt_count' => 1,
    ]);

    app(WebhookService::class)->dispatchForPayment($payment);

    expect(WebhookEvent::where('payment_intent_id', $payment->id)->value('business_profile_id'))->toBe($second->id);
});

test('skips when no signing key configured', function () {
    $this->profile->update(['payment_response_hash_key' => null]);
    Http::fake();

    $job = new DeliverWebhookJob($this->event->id);
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
    expect($this->event->fresh()->last_error)->toContain('signing key');
});

test('sends x-webhook-signature-512 header', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $job = new DeliverWebhookJob($this->event->id);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-webhook-signature-512');
    });
});

test('body carries the time of the fact, not of the last attempt, and is signed', function () {
    $this->travelTo(now()->subHour());
    $event = WebhookEvent::create([
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $this->merchant->id,
        'content' => ['payment_id' => 'pay_old', 'status' => 'succeeded'],
    ]);
    $this->travelBack();
    // Неудачная попытка сдвигает updated_at события.
    $event->update(['delivery_attempts' => 3, 'last_error' => 'HTTP 500']);
    $createdAt = $event->created_at->toIso8601String();
    expect($event->fresh()->updated_at->toIso8601String())->not->toBe($createdAt);

    Http::fake(['*' => Http::response('ok', 200)]);
    app()->call([new DeliverWebhookJob($event->id), 'handle']);

    $key = $this->profile->fresh()->payment_response_hash_key;
    Http::assertSent(function ($request) use ($event, $createdAt, $key) {
        $body = json_decode($request->body(), true);

        return $body['event_id'] === $event->key
            && $body['created'] === $createdAt
            && $body['updated'] === $createdAt
            && $request->header('x-webhook-event-id')[0] === $event->key
            && WebhookSigner::verify($request->body(), $request->header('x-webhook-signature-512')[0], $key);
    });
});

test('blocks SSRF to private IPs', function () {
    expect(UrlSafetyValidator::isSafe('https://example.com/webhook'))->toBeTrue();
    expect(UrlSafetyValidator::isSafe('http://localhost/webhook'))->toBeFalse();
    expect(UrlSafetyValidator::isSafe('http://127.0.0.1/webhook'))->toBeFalse();
});
