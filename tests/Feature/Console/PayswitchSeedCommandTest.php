<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('payswitch:seed creates demo environment', function () {
    $this->artisan('payswitch:seed')
        ->assertSuccessful()
        ->expectsOutputToContain('Demo environment ready');

    $this->assertDatabaseCount('organizations', 1);
    $this->assertDatabaseCount('merchant_accounts', 1);
    $this->assertDatabaseCount('business_profiles', 1);
    $this->assertDatabaseCount('api_keys', 1);
    $this->assertDatabaseCount('merchant_connector_accounts', 2);
});
