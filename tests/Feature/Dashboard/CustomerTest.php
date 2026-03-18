<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('customers list returns json:api response', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'a@b.com']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'c@d.com']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/customers', $this->headers);

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'customers');
});

test('customers list scoped to merchant', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'a@b.com']);

    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);
    Customer::create(['merchant_account_id' => $otherMerchant->id, 'email' => 'x@y.com']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/customers', $this->headers);

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

test('customer detail returns json:api resource', function () {
    $customer = Customer::create(['merchant_account_id' => $this->merchant->id, 'email' => 'a@b.com', 'name' => 'Alice']);

    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/customers/{$customer->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'customers')
        ->assertJsonPath('data.id', $customer->key);
});

test('customer detail requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/customers/cus_fake', $this->headers);

    $response->assertUnauthorized();
});
