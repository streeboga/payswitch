<?php

declare(strict_types=1);

use App\Builders\WebhookEventQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\WebhookEvent;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Test Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Merchant']);
    $this->otherMerchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Other']);
});

function createWebhookEvent(array $attrs = []): WebhookEvent
{
    return WebhookEvent::create(array_merge([
        'event_type' => 'payment.completed',
        'merchant_account_id' => $attrs['merchant_account_id'] ?? 1,
        'content' => ['foo' => 'bar'],
        'delivered' => false,
        'delivery_attempts' => 0,
    ], $attrs));
}

test('make returns a new instance', function () {
    expect(WebhookEventQueryBuilder::make())->toBeInstanceOf(WebhookEventQueryBuilder::class);
});

test('whereId filters by id', function () {
    $event = createWebhookEvent(['merchant_account_id' => $this->merchant->id]);
    createWebhookEvent(['merchant_account_id' => $this->merchant->id]);

    $result = WebhookEventQueryBuilder::make()
        ->whereId($event->id)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($event->id);
});

test('forMerchant filters by merchant_account_id', function () {
    createWebhookEvent(['merchant_account_id' => $this->merchant->id]);
    createWebhookEvent(['merchant_account_id' => $this->otherMerchant->id]);

    $results = WebhookEventQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1);
});

test('pending filters undelivered events with zero attempts', function () {
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'delivered' => false, 'delivery_attempts' => 0]);
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'delivered' => true, 'delivery_attempts' => 1]);
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'delivered' => false, 'delivery_attempts' => 3]);

    $results = WebhookEventQueryBuilder::make()
        ->pending()
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->delivered)->toBeFalse()
        ->and($results->first()->delivery_attempts)->toBe(0);
});

test('delivered filters delivered events', function () {
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'delivered' => true, 'delivery_attempts' => 1]);
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'delivered' => false, 'delivery_attempts' => 0]);

    $results = WebhookEventQueryBuilder::make()
        ->delivered()
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->delivered)->toBeTrue();
});

test('failed filters undelivered events with attempts greater than zero', function () {
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'delivered' => false, 'delivery_attempts' => 3]);
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'delivered' => false, 'delivery_attempts' => 0]);
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'delivered' => true, 'delivery_attempts' => 1]);

    $results = WebhookEventQueryBuilder::make()
        ->failed()
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->delivery_attempts)->toBe(3);
});

test('withEventType filters by event_type', function () {
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'event_type' => 'payment.completed']);
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'event_type' => 'payment.failed']);
    createWebhookEvent(['merchant_account_id' => $this->merchant->id, 'event_type' => 'refund.created']);

    $results = WebhookEventQueryBuilder::make()
        ->withEventType('payment.completed')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->event_type)->toBe('payment.completed');
});

test('orderByLatest orders by created_at descending', function () {
    $first = createWebhookEvent(['merchant_account_id' => $this->merchant->id]);
    WebhookEvent::query()->where('id', $first->id)->update(['created_at' => now()->subMinute()]);

    $second = createWebhookEvent(['merchant_account_id' => $this->merchant->id]);

    $results = WebhookEventQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->orderByLatest()
        ->getQuery()
        ->get();

    expect($results->first()->id)->toBe($second->id);
});

test('whereKey filters by key', function () {
    $event = createWebhookEvent(['merchant_account_id' => $this->merchant->id]);

    $result = WebhookEventQueryBuilder::make()
        ->whereKey($event->key)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($event->id);
});

test('whereKey returns nothing for non-existent key', function () {
    createWebhookEvent(['merchant_account_id' => $this->merchant->id]);

    $result = WebhookEventQueryBuilder::make()
        ->whereKey('evt_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('firstOrFail returns first matching record', function () {
    $event = createWebhookEvent(['merchant_account_id' => $this->merchant->id]);

    $result = WebhookEventQueryBuilder::make()
        ->whereKey($event->key)
        ->firstOrFail();

    expect($result->id)->toBe($event->id);
});

test('firstOrFail throws when no match', function () {
    WebhookEventQueryBuilder::make()
        ->whereKey('evt_nonexistent')
        ->firstOrFail();
})->throws(ModelNotFoundException::class);

test('first returns null when no match', function () {
    $result = WebhookEventQueryBuilder::make()
        ->whereKey('evt_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('paginate returns paginated results', function () {
    for ($i = 0; $i < 5; $i++) {
        createWebhookEvent(['merchant_account_id' => $this->merchant->id]);
    }

    $paginated = WebhookEventQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->paginate(2);

    expect($paginated->total())->toBe(5)
        ->and($paginated->perPage())->toBe(2)
        ->and($paginated->items())->toHaveCount(2);
});

test('paginate defaults to 20 per page', function () {
    createWebhookEvent(['merchant_account_id' => $this->merchant->id]);

    $paginated = WebhookEventQueryBuilder::make()->paginate();

    expect($paginated->perPage())->toBe(20);
});

test('getQuery returns underlying builder', function () {
    $query = WebhookEventQueryBuilder::make()->getQuery();
    expect($query)->toBeInstanceOf(Builder::class);
});

test('methods can be chained together', function () {
    createWebhookEvent([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.completed',
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);
    createWebhookEvent([
        'merchant_account_id' => $this->merchant->id,
        'event_type' => 'payment.failed',
        'delivered' => false,
        'delivery_attempts' => 0,
    ]);
    createWebhookEvent([
        'merchant_account_id' => $this->otherMerchant->id,
        'event_type' => 'payment.completed',
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);

    $results = WebhookEventQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->delivered()
        ->withEventType('payment.completed')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->event_type)->toBe('payment.completed');
});
