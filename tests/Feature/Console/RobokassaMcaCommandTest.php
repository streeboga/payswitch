<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
});

it('refuses to run while ROBOKASSA_* is empty', function () {
    config(['payswitch.robokassa_account.login' => '']);

    $this->artisan('payswitch:robokassa-mca', ['merchant' => $this->merchant->key])->assertFailed();

    expect($this->merchant->connectorAccounts()->count())->toBe(0);
});

it('creates the MCA from env values, test mode on', function () {
    config(['payswitch.robokassa_account' => [
        'login' => 'shop', 'password1' => 'fake-1', 'password2' => 'fake-2',
        'hash_algo' => 'sha256', 'test_mode' => true, 'fiscal' => false,
    ]]);

    $this->artisan('payswitch:robokassa-mca', ['merchant' => $this->merchant->key])->assertSuccessful();

    $mca = $this->merchant->connectorAccounts()->firstOrFail();
    expect($mca->connector_name)->toBe('robokassa')
        ->and($mca->test_mode)->toBeTrue()
        ->and($mca->connector_account_details['hash_algo'])->toBe('sha256');
});
