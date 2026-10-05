<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

function outboxEvent(array $overrides = [], int $minutesAgo = 500): WebhookEvent
{
    $merchant = MerchantAccount::first()
        ?? MerchantAccount::create(['org_id' => Organization::create(['name' => 'Org'])->id, 'name' => 'M']);

    test()->travelTo(now()->subMinutes($minutesAgo));
    $event = WebhookEvent::create($overrides + [
        'event_type' => 'payment_succeeded',
        'merchant_account_id' => $merchant->id,
        'content' => ['payment_id' => 'pay_1'],
    ]);
    test()->travelBack();

    return $event;
}

test('ставит в очередь старые недоставленные события и не создаёт новых', function () {
    Queue::fake();
    $lost = outboxEvent();
    outboxEvent(['delivered' => true]);
    outboxEvent(['delivery_attempts' => 16]);
    outboxEvent(minutesAgo: 30);

    $this->artisan('payswitch:webhooks:retry')
        ->assertSuccessful()
        ->expectsOutputToContain('Enqueued 1 ');

    Queue::assertPushed(DeliverWebhookJob::class, 1);
    Queue::assertPushed(DeliverWebhookJob::class, fn ($job) => $job->webhookEventId === $lost->id);
    expect(WebhookEvent::count())->toBe(4);
});

test('--limit и --older-than', function () {
    Queue::fake();
    outboxEvent();
    outboxEvent();
    outboxEvent(minutesAgo: 30);

    $this->artisan('payswitch:webhooks:retry', ['--limit' => 1])->assertSuccessful();
    Queue::assertPushed(DeliverWebhookJob::class, 1);

    $this->artisan('payswitch:webhooks:retry', ['--older-than' => 10])->expectsOutputToContain('Enqueued 3 ');
});
