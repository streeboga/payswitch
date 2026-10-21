<?php

declare(strict_types=1);

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\Events\PaymentStatusChanged;
use App\Services\PaymentConfirmationService;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Streeboga\PaymentConnectors\ConnectorFactory;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\ReceiptStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentOmnipay\Robokassa\RobokassaConnector;
use Streeboga\PaymentOmnipay\Robokassa\Signature;

/*
 * The Omnipay Robokassa adapter wired through the real registry, payment confirmation and
 * webhook receiver. Passwords are fakes: the Robokassa MCP's own test passwords never enter code.
 */

uses(RefreshDatabase::class);

const RK_P1 = 'fake-pass-1';
const RK_P2 = 'fake-pass-2';

beforeEach(function () {
    ConnectorFactory::register('robokassa', RobokassaConnector::class);
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    $this->mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'robokassa',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => [
            'login' => 'shop', 'password1' => RK_P1, 'password2' => RK_P2, 'test_mode' => true,
            'fiscal' => true, 'sno' => 'usn_income', 'tax' => 'none', 'payment_method' => 'full_payment', 'payment_object' => 'service',
        ],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);
    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'amount' => 10050,
        'currency' => 'RUB',
        'description' => 'Пополнение баланса',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => CaptureMethod::Automatic,
        'return_url' => 'https://merchant.test/return',
    ]);
});

afterEach(fn () => ConnectorFactory::flush());

function rkCallback(object $t, array $override = [], bool $sign = true): array
{
    $data = array_merge(['OutSum' => '100.500000', 'InvId' => (string) $t->payment->id, 'Shp_payment_id' => $t->payment->key, 'Shp_currency' => 'RUB'], $override);
    if ($sign && ! array_key_exists('SignatureValue', $data)) {
        $shp = array_filter($data, fn ($k) => str_starts_with((string) $k, 'Shp_'), ARRAY_FILTER_USE_KEY);
        $data['SignatureValue'] = strtoupper(Signature::result('md5', $data['OutSum'], $data['InvId'], RK_P2, $shp));
    }

    return $data;
}

function rkPost(object $t, array $params)
{
    return $t->call('POST', "/api/v1/webhooks/{$t->merchant->key}/{$t->mca->key}", $params, [], [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($params));
}

test('confirm opens a signed Robokassa form with the receipt and a pending receipt row', function () {
    $payment = app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);

    $meta = $payment->metadata;
    expect($payment->status)->toBe(PaymentStatus::RequiresCustomerAction)
        ->and($meta['type'])->toBe('form_redirect')
        ->and($meta['url'])->toBe('https://auth.robokassa.ru/Merchant/Index.aspx')
        ->and($meta['params']['OutSum'])->toBe('100.50')
        ->and($meta['params']['InvId'])->toBe((string) $payment->id)
        ->and($meta['params']['Shp_payment_id'])->toBe($payment->key)
        ->and($meta['params']['IsTest'])->toBe('1')
        ->and($meta['params'])->toHaveKey('Receipt')
        ->and(json_encode($meta))->not->toContain(RK_P1)->not->toContain(RK_P2);

    $receipt = $payment->receipts()->sole();
    expect($receipt->status)->toBe(ReceiptStatus::Pending)->and($receipt->total)->toBe(10050)->and($receipt->connector)->toBe('robokassa');
});

test('a non-fiscal account sends no receipt and opens no receipt row', function () {
    $this->mca->update(['connector_account_details' => ['login' => 'shop', 'password1' => RK_P1, 'password2' => RK_P2]]);

    $payment = app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);

    expect($payment->metadata['params'])->not->toHaveKey('Receipt')->and($payment->receipts()->count())->toBe(0);
});

test('merchant receipt lines that do not add up to the amount fail the session, not the payer', function () {
    $this->payment->update(['metadata' => ['receipt_items' => [['name' => 'x', 'quantity' => 1, 'sum' => 1]]]]);

    expect(fn () => app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id))
        ->toThrow(Exception::class);
});

test('a signed ResultURL succeeds the payment, answers OK{InvId} and leaves the receipt pending', function () {
    app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);
    Event::fake([PaymentStatusChanged::class]);

    $response = rkPost($this, rkCallback($this));

    $response->assertOk();
    $fresh = $this->payment->fresh();
    expect($response->getContent())->toBe('OK'.$this->payment->id)
        ->and($fresh->status)->toBe(PaymentStatus::Succeeded)
        ->and($fresh->amount_received)->toBe(10050)
        // PSP success is not receipt success.
        ->and($fresh->receipts()->sole()->status)->toBe(ReceiptStatus::Pending);
    Event::assertDispatchedTimes(PaymentStatusChanged::class, 1);
});

test('a ResultURL with empty fields and an odd Content-Type is read from the signed raw body', function () {
    app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);
    $params = rkCallback($this, ['EMail' => '', 'Fee' => '']);

    $response = $this->call('POST', "/api/v1/webhooks/{$this->merchant->key}/{$this->mca->key}", [], [], [],
        ['CONTENT_TYPE' => 'text/plain'], http_build_query($params));

    expect($response->getContent())->toBe('OK'.$this->payment->id)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('a repeated ResultURL is acknowledged and changes nothing', function () {
    app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);
    rkPost($this, rkCallback($this))->assertOk();
    Event::fake([PaymentStatusChanged::class]);

    $again = rkPost($this, rkCallback($this));

    expect($again->getContent())->toBe('OK'.$this->payment->id)->and($this->payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
    Event::assertNotDispatched(PaymentStatusChanged::class);
});

test('a bad signature is refused with 401 and the payment does not move', function () {
    $response = rkPost($this, rkCallback($this, ['SignatureValue' => str_repeat('0', 32)]));

    $response->assertStatus(401);
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::RequiresPaymentMethod);
});

test('an unsigned currency is refused: the callback must name the currency back', function () {
    $params = rkCallback($this, ['Shp_currency' => '']);
    unset($params['Shp_currency']);
    $params['SignatureValue'] = Signature::result('md5', $params['OutSum'], $params['InvId'], RK_P2, ['Shp_payment_id' => $this->payment->key]);

    rkPost($this, $params)->assertStatus(401);
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::RequiresPaymentMethod);
});

test('money that is not the money billed is never called paid', function (array $override, string $code) {
    rkPost($this, rkCallback($this, $override))->assertOk();

    $fresh = $this->payment->fresh();
    expect($fresh->status)->toBe(PaymentStatus::RequiresMerchantAction)->and($fresh->error_code)->toBe($code);
})->with([
    'amount' => [['OutSum' => '1.000000'], 'amount_mismatch'],
    'currency' => [['Shp_currency' => 'USD'], 'currency_mismatch'],
]);

test('a callback for another connector account cannot move this account\'s payment', function () {
    $other = MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id, 'business_profile_id' => $this->profile->id,
        'connector_name' => 'robokassa', 'connector_type' => 'fiz_operations',
        'connector_account_details' => ['login' => 'x', 'password1' => 'a', 'password2' => 'other-secret'], 'test_mode' => true,
    ]);
    $params = rkCallback($this, ['EMail' => '', 'Fee' => '']);

    // Signed with Password#2 of this account, posted to the other one's URL.
    $this->call('POST', "/api/v1/webhooks/{$this->merchant->key}/{$other->key}", $params, [], [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($params))->assertStatus(401);
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::RequiresPaymentMethod);
});

test('receipt state moves only by a fiscal signal and registered is final', function () {
    app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);
    $service = app(PaymentReceiptService::class);
    $receipt = $this->payment->receipts()->sole();

    expect($service->markFailed($receipt, 'ofd_error', 'timeout'))->toBeTrue();
    expect($receipt->fresh()->status)->toBe(ReceiptStatus::Failed);
    expect($service->markRegistered($receipt->fresh(), 'fn-1', 'https://ofd.test/r/1'))->toBeTrue();
    expect($service->markFailed($receipt->fresh(), 'x', 'y'))->toBeFalse();
    expect($receipt->fresh())->status->toBe(ReceiptStatus::Registered)->fiscal_url->toBe('https://ofd.test/r/1')->error_code->toBeNull();
});

function rkFormFor(object $t, bool $column, array $details): array
{
    $t->mca->update(['test_mode' => $column, 'connector_account_details' => ['login' => 'shop', 'password1' => RK_P1, 'password2' => RK_P2] + $details]);
    $res = ConnectorFactory::resolve($t->mca->refresh())->createPaymentSession([
        'payment_id' => $t->payment->key, 'amount' => 10050, 'currency' => 'RUB', 'invoice_number' => $t->payment->id,
    ]);

    return $res->toArray()['params'];
}

it('takes IsTest from the account test_mode column when the details do not say', function () {
    expect(rkFormFor($this, true, []))->toHaveKey('IsTest', '1')
        ->and(rkFormFor($this, false, []))->not->toHaveKey('IsTest');
});

it('lets an explicit test_mode in the details win over the column', function () {
    expect(rkFormFor($this, true, ['test_mode' => false]))->not->toHaveKey('IsTest')
        ->and(rkFormFor($this, false, ['test_mode' => true]))->toHaveKey('IsTest', '1');
});

test('a payment awaiting the customer reopens the session when another method is chosen', function () {
    $first = app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'card'), $this->merchant->id);
    expect($first->status)->toBe(PaymentStatus::RequiresCustomerAction);

    $second = app(PaymentConfirmationService::class)->confirm($this->payment->key, new ConfirmPaymentData(payment_method: 'sbp'), $this->merchant->id);

    expect($second->status)->toBe(PaymentStatus::RequiresCustomerAction)
        ->and($second->metadata['params']['IncCurrLabel'])->toBe('SBP')
        ->and($second->metadata['params']['InvId'])->toBe($first->metadata['params']['InvId']);
});
