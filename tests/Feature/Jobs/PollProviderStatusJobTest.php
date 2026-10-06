<?php

declare(strict_types=1);

use App\Jobs\PollProviderStatusJob;
use App\Services\ProviderPollingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\WebhookEvent;
use Tests\Helpers\ScriptedConnector;

uses(RefreshDatabase::class);

/**
 * Платёж «в обработке» не ждёт уведомления вечно: провайдера спрашивает расписание.
 */
beforeEach(function () {
    Queue::fake();
    ScriptedConnector::register('scripted');

    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    foreach (['scripted', 'sberbank', 'test'] as $connector) {
        MerchantConnectorAccount::create([
            'merchant_account_id' => $this->merchant->id, 'business_profile_id' => $this->profile->id,
            'connector_name' => $connector, 'connector_type' => 'fiz_operations',
            'connector_account_details' => ['userName' => 'u', 'password' => 'p'], 'test_mode' => true,
        ]);
    }

    // Платёж, вошедший в статус $minutesAgo минут назад, с транзакцией у провайдера.
    $this->stuck = function (string $connector = 'scripted', PaymentStatus $status = PaymentStatus::Processing, int $minutesAgo = 2, bool $transaction = true): PaymentIntent {
        $this->travelTo(now()->subMinutes($minutesAgo));
        $payment = PaymentIntent::create([
            'merchant_account_id' => $this->merchant->id, 'business_profile_id' => $this->profile->id,
            'amount' => 10000, 'currency' => 'RUB', 'status' => $status, 'connector' => $connector,
            'error_code' => $status === PaymentStatus::Processing ? 'amount_unconfirmed' : null,
        ]);
        $payment->paymentAttempts()->create(['connector' => $connector, 'connector_transaction_id' => $transaction ? 'txn_1' : null, 'status' => 'requires_action', 'amount' => 10000]);
        $this->travelBack();

        return $payment;
    };

    $this->answer = fn (array $data) => ScriptedConnector::$script['getPaymentStatus'] = ['success' => true, 'transaction_id' => 'txn_1', 'code' => 'ok', 'message' => 'ok', 'data' => $data];
    $this->poll = fn () => app()->call([new PollProviderStatusJob, 'handle']);
    $this->events = fn () => WebhookEvent::query()->orderBy('id')->get()->map(fn (WebhookEvent $e) => [$e->event_type, $e->content['status']])->all();
});

test('провайдер назвал сумму — платёж оплачен, мерчанту одно payment_succeeded', function () {
    $payment = ($this->stuck)();
    ($this->answer)(['status' => 'succeeded', 'amount' => 10000]);

    ($this->poll)();
    ($this->poll)();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->fresh()->amount_received)->toBe(10000)
        ->and($payment->fresh()->error_code)->toBeNull()
        ->and(($this->events)())->toBe([['payment_succeeded', 'succeeded']])
        ->and(ScriptedConnector::callsTo('getPaymentStatus'))->toHaveCount(1);
});

test('отказ провайдера и чужая сумма — обычные переходы sync', function (array $data, PaymentStatus $expected) {
    $payment = ($this->stuck)();
    ($this->answer)($data);

    ($this->poll)();

    expect($payment->fresh()->status)->toBe($expected)
        ->and(($this->events)())->toBe([['payment_status_changed', $expected->value]]);
})->with([
    'отказ' => [['status' => 'failed'], PaymentStatus::Failed],
    'чужая сумма' => [['status' => 'succeeded', 'amount' => 100], PaymentStatus::RequiresMerchantAction],
]);

test('ждущий плательщика платёж тоже опрашивается', function () {
    $payment = ($this->stuck)(status: PaymentStatus::RequiresCustomerAction);
    ($this->answer)(['status' => 'succeeded', 'amount' => 10000]);

    ($this->poll)();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('RBS: сумму называет getOrderStatusExtended, хотя обратный вызов её не несёт', function () {
    $payment = ($this->stuck)('sberbank');
    Http::fake(['*/getOrderStatusExtended.do' => Http::response(['errorCode' => '0', 'orderStatus' => 2, 'amount' => 10000, 'orderNumber' => $payment->key])]);

    ($this->poll)();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->fresh()->amount_received)->toBe(10000);
    Http::assertSent(fn ($request) => $request['orderId'] === 'txn_1');
});

test('не опрашиваются: свежий, без транзакции, тестовый коннектор, уже конечный, занятый другим процессом', function () {
    ($this->answer)(['status' => 'succeeded', 'amount' => 10000]);
    ($this->stuck)(minutesAgo: 0);
    ($this->stuck)(transaction: false);
    ($this->stuck)('test', PaymentStatus::RequiresCustomerAction);
    ($this->stuck)(status: PaymentStatus::Failed);
    $busy = ($this->stuck)();
    Cache::lock("provider-poll:payment_intents:{$busy->id}", 60)->get();

    ($this->poll)();

    expect(ScriptedConnector::callsTo('getPaymentStatus'))->toBe([])
        ->and(WebhookEvent::count())->toBe(0);
});

test('интервал нарастает: 1, 2, 5, 15, 60 минут, дальше раз в 6 часов', function () {
    $payment = ($this->stuck)(minutesAgo: 1);
    ($this->answer)(['status' => 'succeeded']); // суммы так и нет

    $polledAt = [];
    $start = now();
    for ($minute = 1; $minute <= 500; $minute++) {
        $before = count(ScriptedConnector::callsTo('getPaymentStatus'));
        ($this->poll)();
        if (count(ScriptedConnector::callsTo('getPaymentStatus')) > $before) {
            $polledAt[] = (int) $start->diffInMinutes(now()) + 1;
        }
        $this->travel(1)->minutes();
    }

    expect($polledAt)->toBe([1, 3, 8, 23, 83, 443])
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Processing);
});

test('сутки без итога: processing уходит на разбор мерчанту с событием и больше не опрашивается', function () {
    $payment = ($this->stuck)();
    ScriptedConnector::$script['getPaymentStatus'] = new RuntimeException('provider is down');

    for ($i = 0; $i < 12; $i++) {
        ($this->poll)();
        $this->travel(6)->hours();
    }

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresMerchantAction)
        ->and($payment->fresh()->error_code)->toBe(ProviderPollingService::UNCONFIRMED)
        ->and(($this->events)())->toBe([['payment_status_changed', 'requires_merchant_action']])
        ->and(ScriptedConnector::callsTo('getPaymentStatus'))->toHaveCount(count(ProviderPollingService::SCHEDULE));
});

test('смена статуса начинает опрос заново', function () {
    $payment = ($this->stuck)(status: PaymentStatus::RequiresCustomerAction);
    $payment->update(['poll_attempts' => 4, 'next_poll_at' => now()->addHour()]);

    $payment->update(['status' => PaymentStatus::Processing]);

    expect($payment->fresh()->poll_attempts)->toBe(0)
        ->and($payment->fresh()->next_poll_at)->toBeNull();
});

test('пачка ограничена, истёкший бюджет времени опрос не начинает', function () {
    ($this->answer)(['status' => 'succeeded', 'amount' => 10000]);
    foreach (range(1, 3) as $i) {
        ($this->stuck)();
    }

    app(ProviderPollingService::class)->run(2, microtime(true) - 1);
    expect(ScriptedConnector::callsTo('getPaymentStatus'))->toHaveCount(0);

    app(ProviderPollingService::class)->run(2, microtime(true) + 30);
    expect(ScriptedConnector::callsTo('getPaymentStatus'))->toHaveCount(2);
});

test('опрос стоит в расписании каждую минуту', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->description, 'PollProviderStatusJob'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
