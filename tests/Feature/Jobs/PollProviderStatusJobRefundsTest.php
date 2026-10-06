<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use App\Jobs\PollProviderStatusJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Streeboga\PaymentData\Models\WebhookEvent;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'connector_name' => 'yookassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['shop_id' => '1', 'secret_key' => 's'],
        'test_mode' => true,
    ]);
    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $profile->id,
        'amount' => 10000,
        'amount_received' => 10000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => 'automatic',
        'connector' => 'yookassa',
        'attempt_count' => 1,
    ]);
    PaymentAttempt::create([
        'payment_intent_id' => $this->payment->id,
        'connector' => 'yookassa',
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'RUB',
        'connector_transaction_id' => 'yk_pay_1',
    ]);
});

function pendingRefund(array $overrides = [], int $minutesAgo = 5): Refund
{
    test()->travelTo(now()->subMinutes($minutesAgo));
    $refund = Refund::create($overrides + [
        'payment_intent_id' => test()->payment->id,
        'merchant_account_id' => test()->merchant->id,
        'amount' => 4000,
        'currency' => 'RUB',
        'status' => RefundStatus::Pending,
        'connector' => 'yookassa',
        'connector_refund_id' => 'yk_ref_1',
    ]);
    test()->travelBack();

    return $refund;
}

function yooKassaRefund(array $overrides = []): void
{
    Http::fake(['api.yookassa.ru/v3/refunds/*' => Http::response($overrides + [
        'id' => 'yk_ref_1',
        'status' => 'succeeded',
        'payment_id' => 'yk_pay_1',
        'amount' => ['value' => '40.00', 'currency' => 'RUB'],
    ])]);
}

function runRefundReconcile(): void
{
    app()->call([new PollProviderStatusJob, 'handle']);
}

test('провайдер подтвердил возврат: succeeded и одно refund_succeeded, запрос только на чтение', function () {
    $refund = pendingRefund();
    yooKassaRefund();

    runRefundReconcile();
    runRefundReconcile();

    $event = WebhookEvent::sole();
    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($event->event_type)->toBe('refund_succeeded')
        ->and($event->content['refund_id'])->toBe($refund->key);
    Queue::assertPushed(DeliverWebhookJob::class, 1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/refunds/yk_ref_1'));
});

test('провайдер отменил возврат: failed и refund_failed', function () {
    $refund = pendingRefund();
    yooKassaRefund(['status' => 'canceled']);

    runRefundReconcile();

    expect($refund->fresh()->status)->toBe(RefundStatus::Failed)
        ->and($refund->fresh()->error_code)->toBe('refund_canceled')
        ->and(WebhookEvent::sole()->event_type)->toBe('refund_failed');
});

test('у провайдера всё ещё pending, ошибка или 5xx — возврат остаётся pending без события', function (array|int $response) {
    $refund = pendingRefund();
    is_int($response)
        ? Http::fake(['*' => Http::response('', $response)])
        : yooKassaRefund($response);

    runRefundReconcile();

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending)
        ->and(WebhookEvent::count())->toBe(0);
})->with([
    'pending' => [['status' => 'pending']],
    'ошибка провайдера' => [['type' => 'error', 'code' => 'not_found', 'id' => 'x']],
    '503' => [503],
]);

test('ответ про другой платёж, сумму или валюту возврат не закрывает', function (array $changed) {
    Log::spy();
    $refund = pendingRefund();
    yooKassaRefund($changed);

    runRefundReconcile();

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending)
        ->and(WebhookEvent::count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'does not match'))->once();
})->with([
    'платёж' => [['payment_id' => 'yk_pay_other']],
    'сумма' => [['amount' => ['value' => '40.01', 'currency' => 'RUB']]],
    'валюта' => [['amount' => ['value' => '40.00', 'currency' => 'USD']]],
]);

test('не трогает свежие, без id провайдера, уже итоговые и возвраты коннектора без чтения', function () {
    yooKassaRefund();
    pendingRefund(minutesAgo: 0);
    pendingRefund(['connector_refund_id' => null]);
    pendingRefund(['status' => RefundStatus::Failed, 'connector_refund_id' => 'yk_ref_2']);
    pendingRefund(['connector' => 'test', 'connector_refund_id' => 'yk_ref_3']);

    runRefundReconcile();

    Http::assertNothingSent();
    expect(WebhookEvent::count())->toBe(0);
});

test('возврат без итога опрашивается с нарастающим интервалом, через сутки — запись в лог и больше не опрашивается', function () {
    Log::spy();
    $refund = pendingRefund();
    yooKassaRefund(['status' => 'pending']);

    runRefundReconcile();
    runRefundReconcile(); // срок следующего опроса не настал
    Http::assertSentCount(1);
    expect($refund->fresh()->poll_attempts)->toBe(1);

    for ($i = 0; $i < 12; $i++) {
        $this->travel(6)->hours();
        runRefundReconcile();
    }

    Http::assertSentCount(9);
    expect($refund->fresh()->status)->toBe(RefundStatus::Pending)
        ->and($refund->fresh()->next_poll_at)->toBeNull()
        ->and(WebhookEvent::count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'refund still pending after a day'))->once();
});
