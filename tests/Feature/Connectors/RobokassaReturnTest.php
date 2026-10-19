<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

function rkMerchant(string $name, string $connector = 'robokassa'): array
{
    $org = Organization::create(['name' => $name]);
    $merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => $name]);
    $profile = BusinessProfile::create(['merchant_account_id' => $merchant->id]);
    $mca = MerchantConnectorAccount::create([
        'merchant_account_id' => $merchant->id, 'business_profile_id' => $profile->id,
        'connector_name' => $connector, 'connector_type' => 'fiz_operations',
        'connector_account_details' => ['login' => 'shop', 'password1' => 'p1', 'password2' => 'p2'],
        'payment_methods_enabled' => [['payment_method' => 'card']], 'test_mode' => true,
    ]);
    $payment = PaymentIntent::create([
        'merchant_account_id' => $merchant->id, 'business_profile_id' => $profile->id,
        'amount' => 10050, 'currency' => 'RUB', 'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => CaptureMethod::Automatic, 'return_url' => 'https://shop.test/back?order=5',
    ]);

    return [$merchant, $mca, $payment];
}

function rkReturnUrl(object $merchant, object $mca, string $outcome = 'success'): string
{
    return "/api/v1/pay/robokassa/{$merchant->key}/{$mca->key}/{$outcome}";
}

it('sends the buyer to the payment return_url with the status read from storage, not from the query', function () {
    [$m, $mca, $p] = rkMerchant('A');

    $res = $this->get(rkReturnUrl($m, $mca).'?InvId='.$p->id.'&Shp_payment_id='.$p->key.'&status=succeeded&OutSum=1');

    $res->assertRedirect('https://shop.test/back?order=5&payment_id='.$p->key.'&status=requires_payment_method');
});

it('serves the fail URL by POST as well', function () {
    [$m, $mca, $p] = rkMerchant('A');

    $this->post(rkReturnUrl($m, $mca, 'fail'), ['InvId' => (string) $p->id, 'Shp_payment_id' => $p->key])
        ->assertRedirectContains('payment_id='.$p->key);
});

it('shows a plain status page when the payment has no return_url', function () {
    [$m, $mca, $p] = rkMerchant('A');
    $p->update(['return_url' => null]);

    $this->get(rkReturnUrl($m, $mca).'?InvId='.$p->id.'&Shp_payment_id='.$p->key)
        ->assertOk()->assertSee($p->key)->assertSee('requires_payment_method');
});

it('does not reveal a payment by a guessed InvId or a foreign one', function () {
    [$m, $mca, $p] = rkMerchant('A');
    [, , $other] = rkMerchant('B');

    $this->get(rkReturnUrl($m, $mca).'?InvId='.$p->id)->assertNotFound();
    $this->get(rkReturnUrl($m, $mca).'?InvId='.$other->id.'&Shp_payment_id='.$other->key)->assertNotFound();
    $this->get(rkReturnUrl($m, $mca).'?InvId='.($p->id + 1000).'&Shp_payment_id='.$p->key)->assertNotFound();
});

it('refuses a connector account that is not Robokassa', function () {
    [$m, $mca, $p] = rkMerchant('A', 'test');

    $this->get(rkReturnUrl($m, $mca).'?InvId='.$p->id.'&Shp_payment_id='.$p->key)->assertNotFound();
});
