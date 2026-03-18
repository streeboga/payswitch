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

test('skips when no webhook url configured', function () {
    $this->profile->update(['webhook_url' => null]);
    Http::fake();

    $job = new DeliverWebhookJob($this->event->id);
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
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

test('blocks SSRF to private IPs', function () {
    $job = new DeliverWebhookJob($this->event->id);
    $reflection = new ReflectionMethod($job, 'isUrlSafe');

    expect($reflection->invoke($job, 'https://example.com/webhook'))->toBeTrue();
    expect($reflection->invoke($job, 'http://localhost/webhook'))->toBeFalse();
    expect($reflection->invoke($job, 'http://127.0.0.1/webhook'))->toBeFalse();
});
