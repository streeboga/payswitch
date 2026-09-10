<?php

declare(strict_types=1);

use App\Events\PaymentStatusChanged;
use App\Listeners\DepositToWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'payswitch.wallets.url' => 'https://wallets.test',
        'payswitch.wallets.internal_secret' => 'shh',
    ]);

    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
});

function makePayment(array $attributes = []): PaymentIntent
{
    return PaymentIntent::create(array_merge([
        'merchant_account_id' => test()->merchant->id,
        'amount' => 150000,
        'amount_received' => 150000,
        'currency' => 'RUB',
        'status' => PaymentStatus::Succeeded,
        'capture_method' => CaptureMethod::Automatic,
        'attempt_count' => 1,
    ], $attributes));
}

test('succeeded payment with wallet_key credits the wallet', function () {
    Http::fake(['wallets.test/*' => Http::response(['data' => []], 201)]);

    $payment = makePayment(['metadata' => ['wallet_key' => 'wal_abc']]);

    (new DepositToWallet)->handle(new PaymentStatusChanged($payment, 'processing'));

    Http::assertSent(function ($request) use ($payment) {
        return $request->url() === 'https://wallets.test/internal/wallets/deposit'
            && $request->header('X-Internal-Secret')[0] === 'shh'
            && $request['wallet_key'] === 'wal_abc'
            && $request['amount'] === 150000
            && $request['idempotency_key'] === 'psw_'.$payment->key
            && $request['reference_id'] === $payment->key;
    });
});

test('idempotency key is stable across redeliveries', function () {
    Http::fake(['wallets.test/*' => Http::response(['data' => []], 201)]);

    $payment = makePayment(['metadata' => ['wallet_key' => 'wal_abc']]);
    $listener = new DepositToWallet;

    $listener->handle(new PaymentStatusChanged($payment, 'processing'));
    $listener->handle(new PaymentStatusChanged($payment, 'processing'));

    $keys = [];
    Http::assertSentCount(2);
    Http::recorded(function ($request) use (&$keys) {
        $keys[] = $request['idempotency_key'];

        return true;
    });

    expect(array_unique($keys))->toHaveCount(1);
});

test('payment without wallet_key is ignored', function () {
    Http::fake();

    (new DepositToWallet)->handle(new PaymentStatusChanged(makePayment(), 'processing'));

    Http::assertNothingSent();
});

test('non succeeded payment is ignored', function () {
    Http::fake();

    $payment = makePayment([
        'status' => PaymentStatus::Failed,
        'metadata' => ['wallet_key' => 'wal_abc'],
    ]);

    (new DepositToWallet)->handle(new PaymentStatusChanged($payment, 'processing'));

    Http::assertNothingSent();
});

test('a failing wallets service throws so the job retries', function () {
    Http::fake(['wallets.test/*' => Http::response(['error' => 'boom'], 500)]);

    $payment = makePayment(['metadata' => ['wallet_key' => 'wal_abc']]);

    expect(fn () => (new DepositToWallet)->handle(new PaymentStatusChanged($payment, 'processing')))
        ->toThrow(RequestException::class);
});
