<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('get user preferences returns defaults', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/settings', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'user-preferences')
        ->assertJsonPath('data.attributes.timezone', 'UTC')
        ->assertJsonPath('data.attributes.theme', 'auto');
});

test('update user preferences', function () {
    $response = $this->actingAs($this->user)
        ->patchJson('/api/v1/dashboard/settings', [
            'timezone' => 'Europe/Moscow',
            'theme' => 'dark',
            'base_currency' => 'RUB',
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.timezone', 'Europe/Moscow')
        ->assertJsonPath('data.attributes.theme', 'dark')
        ->assertJsonPath('data.attributes.base_currency', 'RUB');
});

test('settings require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/settings', $this->headers);
    $response->assertUnauthorized();
});
